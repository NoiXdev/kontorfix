<?php

use App\Enums\NotificationEvent;
use App\Jobs\SendNotificationDigest;
use App\Mail\FailureDigest;
use App\Models\NotificationEventRecord;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Support\DigestSummary;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;

function operatorOrgWithCadence(string $cadence, ?string $lastSentAt = null): Organization
{
    return Organization::factory()->create([
        'is_operator' => true,
        'notification_cadence' => $cadence,
        'last_digest_sent_at' => $lastSentAt,
    ]);
}

function recordFailure(Organization $org, string $type = 'sync.failed', string $label = 'acme/demo'): NotificationEventRecord
{
    return NotificationEventRecord::create([
        'organization_id' => $org->id,
        'type' => $type,
        'subject_label' => $label,
        'summary' => 'timeout',
        'occurred_at' => now(),
    ]);
}

function subscriber(Organization $org, string $email, array $events, bool $enabled = true): NotificationRecipient
{
    return NotificationRecipient::create([
        'organization_id' => $org->id, 'email' => $email, 'events' => $events, 'enabled' => $enabled,
    ]);
}

it('mails a due organization and marks the events reported', function () {
    Mail::fake();
    $org = operatorOrgWithCadence('hourly');
    $event = recordFailure($org);
    subscriber($org, 'ops@example.test', ['sync.failed']);

    (new SendNotificationDigest)->handle();

    Mail::assertSent(FailureDigest::class, fn (FailureDigest $m) => $m->hasTo('ops@example.test'));
    expect($event->fresh()->notified_at)->not->toBeNull()
        ->and($org->fresh()->last_digest_sent_at)->not->toBeNull();
});

it('sends nothing again on a second run', function () {
    Mail::fake();
    $org = operatorOrgWithCadence('hourly');
    recordFailure($org);
    subscriber($org, 'ops@example.test', ['sync.failed']);

    (new SendNotificationDigest)->handle();
    Mail::assertSentCount(1);

    (new SendNotificationDigest)->handle();
    Mail::assertSentCount(1);
});

it('leaves a daily organization alone until a day has passed', function () {
    Mail::fake();
    $org = operatorOrgWithCadence('daily', now()->subHours(2)->toDateTimeString());
    recordFailure($org);
    subscriber($org, 'ops@example.test', ['sync.failed']);

    (new SendNotificationDigest)->handle();

    Mail::assertNothingSent();
});

it('sends for a daily organization once a day has passed', function () {
    Mail::fake();
    $org = operatorOrgWithCadence('daily', now()->subDays(2)->toDateTimeString());
    recordFailure($org);
    subscriber($org, 'ops@example.test', ['sync.failed']);

    (new SendNotificationDigest)->handle();

    Mail::assertSentCount(1);
});

it('never sends for an organization set to off', function () {
    Mail::fake();
    $org = operatorOrgWithCadence('off');
    $event = recordFailure($org);
    subscriber($org, 'ops@example.test', ['sync.failed']);

    (new SendNotificationDigest)->handle();

    Mail::assertNothingSent();
    expect($event->fresh()->notified_at)->toBeNull();
});

it('gives each recipient only the event types they subscribe to', function () {
    Mail::fake();
    $org = operatorOrgWithCadence('hourly');
    recordFailure($org, 'sync.failed', 'acme/demo');
    recordFailure($org, 'webhook.delivery_failed', 'https://hooks.example.test/a');
    subscriber($org, 'sync-only@example.test', ['sync.failed']);
    subscriber($org, 'hooks-only@example.test', ['webhook.delivery_failed']);

    (new SendNotificationDigest)->handle();

    Mail::assertSent(FailureDigest::class, function (FailureDigest $m): bool {
        return $m->hasTo('sync-only@example.test')
            && count($m->lines) === 1
            && $m->lines[0]->subjectLabel === 'acme/demo';
    });
    Mail::assertSent(FailureDigest::class, function (FailureDigest $m): bool {
        return $m->hasTo('hooks-only@example.test')
            && count($m->lines) === 1
            && $m->lines[0]->subjectLabel === 'https://hooks.example.test/a';
    });
});

it('skips a disabled recipient', function () {
    Mail::fake();
    $org = operatorOrgWithCadence('hourly');
    recordFailure($org);
    subscriber($org, 'off@example.test', ['sync.failed'], enabled: false);

    (new SendNotificationDigest)->handle();

    Mail::assertNothingSent();
});

it('leaves an event unreported when nobody subscribes to its type', function () {
    Mail::fake();
    $org = operatorOrgWithCadence('hourly');
    $sync = recordFailure($org, 'sync.failed');
    $hook = recordFailure($org, 'webhook.delivery_failed', 'https://hooks.example.test/a');
    subscriber($org, 'sync-only@example.test', ['sync.failed']);

    (new SendNotificationDigest)->handle();

    expect($sync->fresh()->notified_at)->not->toBeNull()
        ->and($hook->fresh()->notified_at)->toBeNull();
});

it('does not mail an organization with nothing to report', function () {
    Mail::fake();
    $org = operatorOrgWithCadence('hourly');
    subscriber($org, 'ops@example.test', ['sync.failed']);

    (new SendNotificationDigest)->handle();

    Mail::assertNothingSent();
});

it('renders the failure digest mail with the folded lines', function () {
    $org = operatorOrgWithCadence('hourly');
    $mail = new FailureDigest($org, DigestSummary::fold([
        (object) [
            'type' => NotificationEvent::SyncFailed->value,
            'subject_label' => 'acme/demo',
            'summary' => 'timeout talking to upstream',
            'occurred_at' => now(),
        ],
    ]));

    $rendered = (string) $mail->render();

    expect($rendered)->toContain('acme/demo')
        ->and($rendered)->toContain('timeout talking to upstream')
        ->and($rendered)->not->toBeEmpty();
});

it('leaves rows pending when the mailer throws instead of marking them reported', function () {
    Mail::shouldReceive('to')->andReturnUsing(function (string $email) {
        return tap(Mockery::mock(PendingMail::class), function ($pending) {
            $pending->shouldReceive('send')->andThrow(new RuntimeException('smtp exploded'));
        });
    });

    $org = operatorOrgWithCadence('hourly');
    $event = recordFailure($org);
    subscriber($org, 'ops@example.test', ['sync.failed']);

    expect(fn () => (new SendNotificationDigest)->handle())->toThrow(RuntimeException::class);

    expect($event->fresh()->notified_at)->toBeNull()
        ->and($org->fresh()->last_digest_sent_at)->toBeNull();
});

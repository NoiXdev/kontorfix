<?php

use App\Enums\NotificationEvent;
use App\Jobs\SendNotificationDigest;
use App\Mail\FailureDigest;
use App\Models\NotificationEventRecord;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Support\DigestSummary;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

// Distinct from the test above: that one starts with `last_digest_sent_at = null`, so its
// second run is refused by isDue() before the pending query ever runs — it proves the
// cadence gate, not that already-reported rows are excluded. This one starts already due
// (two hours into an hourly cadence) so the pending query always runs, and it is the
// `->whereNull('notified_at')` clause alone that must keep the already-marked row out.
// Deleting that clause at SendNotificationDigest.php keeps every other test in this file
// green but turns this one red.
it('mails only the still-open row, leaving an already-reported row out of the digest', function () {
    Mail::fake();
    $org = operatorOrgWithCadence('hourly', now()->subHours(2)->toDateTimeString());
    $reportedAt = now()->subHour();
    $alreadyReported = recordFailure($org, 'sync.failed', 'acme/already-reported');
    $alreadyReported->update(['notified_at' => $reportedAt]);
    $open = recordFailure($org, 'sync.failed', 'acme/still-open');
    subscriber($org, 'ops@example.test', ['sync.failed']);

    (new SendNotificationDigest)->handle();

    Mail::assertSent(FailureDigest::class, fn (FailureDigest $m) => count($m->lines) === 1
        && $m->lines[0]->subjectLabel === 'acme/still-open');
    expect($open->fresh()->notified_at)->not->toBeNull()
        ->and($alreadyReported->fresh()->notified_at->toDateTimeString())
        ->toBe($reportedAt->toDateTimeString());
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

// Reproduces the drift from Finding 2: the mock send advances the clock mid-run, the way a
// slow synchronous SMTP send would. Stamping last_digest_sent_at with the time sending
// finished (10:00:05) rather than when the run started (10:00:00) is exactly the bug that
// makes an hourly digest fire every two hours — the next run's `now()->subHour()` would
// then land after the stamp, so isDue() would refuse a run that should be due.
it('stamps last_digest_sent_at with the run start time, not when sending finished', function () {
    Carbon::setTestNow('2026-01-01 10:00:00');
    Mail::shouldReceive('to')->andReturnUsing(function (string $email) {
        return tap(Mockery::mock(PendingMail::class), function ($pending) {
            $pending->shouldReceive('send')->andReturnUsing(function () {
                Carbon::setTestNow(now()->addSeconds(5));
            });
        });
    });

    $org = operatorOrgWithCadence('hourly');
    recordFailure($org);
    subscriber($org, 'ops@example.test', ['sync.failed']);

    (new SendNotificationDigest)->handle();

    expect($org->fresh()->last_digest_sent_at->toDateTimeString())->toBe('2026-01-01 10:00:00');

    Carbon::setTestNow();
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

// occurred_at is timestamp(0) (second resolution), so any two failures recorded in the
// same second tie. Ids are chosen so that ascending-id order is the *reverse* of insertion
// order: a fetch relying on incidental physical/insertion row order (i.e. missing the
// secondary `orderBy('id')`) would read third-inserted, second-inserted, first-inserted,
// while an id-ordered fetch reads first-inserted, second-inserted, third-inserted. Pinning
// the id relationship this way, rather than trusting Postgres to happen to preserve
// insertion order, is what makes this test able to fail if the secondary sort is dropped.
it('breaks a same-second occurred_at tie by id, deterministically, not by incidental row order', function () {
    Mail::fake();
    $org = operatorOrgWithCadence('hourly');
    $tie = now();

    $third = NotificationEventRecord::forceCreate([
        'id' => '00000000-0000-0000-0000-000000000003',
        'organization_id' => $org->id, 'type' => 'sync.failed', 'subject_label' => 'third-inserted',
        'summary' => 'x', 'occurred_at' => $tie,
    ]);
    $first = NotificationEventRecord::forceCreate([
        'id' => '00000000-0000-0000-0000-000000000001',
        'organization_id' => $org->id, 'type' => 'sync.failed', 'subject_label' => 'first-inserted',
        'summary' => 'x', 'occurred_at' => $tie,
    ]);
    $second = NotificationEventRecord::forceCreate([
        'id' => '00000000-0000-0000-0000-000000000002',
        'organization_id' => $org->id, 'type' => 'sync.failed', 'subject_label' => 'second-inserted',
        'summary' => 'x', 'occurred_at' => $tie,
    ]);
    subscriber($org, 'ops@example.test', ['sync.failed']);

    (new SendNotificationDigest)->handle();

    Mail::assertSent(FailureDigest::class, function (FailureDigest $m): bool {
        // DigestSummary::fold keeps first-appearance order on an exact occurred_at tie, so
        // the query's fetch order is directly visible in the folded line order.
        return array_map(fn ($l) => $l->subjectLabel, $m->lines) === [
            'first-inserted', 'second-inserted', 'third-inserted',
        ];
    });
});

it('renders the failure digest mail with the folded lines and the German event type label', function () {
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
        ->and($rendered)->toContain(NotificationEvent::SyncFailed->label())
        ->and($rendered)->not->toBeEmpty();
});

// git stderr (the real source of `summary`, via GitRepository::sync()) is routinely
// multi-line and can legitimately contain a pipe (a URL, a branch name, ...). Str::limit()
// alone strips neither, so a raw newline used to end the GFM table row early — dumping
// every following `| ... |` line as stray text — and a raw pipe used to insert an extra
// column. Both must be neutralised before the summary reaches the table.
it('keeps the mail table well-formed when a summary is multi-line and contains a pipe', function () {
    $org = operatorOrgWithCadence('hourly');
    $summary = "git clone failed: Cloning into '...'...\n"
        ."fatal: could not read Username for 'https://example.test|evil': terminal prompts disabled";
    $mail = new FailureDigest($org, DigestSummary::fold([
        (object) [
            'type' => NotificationEvent::SyncFailed->value,
            'subject_label' => 'acme/demo',
            'summary' => $summary,
            'occurred_at' => now(),
        ],
    ]));

    $rendered = (string) $mail->render();

    preg_match('/<tbody.*?<\/tbody>/s', $rendered, $tbody);

    expect($tbody)->not->toBeEmpty();
    $body = $tbody[0];

    // Exactly one data row: a newline that broke the row early would end the table there
    // (CommonMark stops parsing at the line break), so the row would never make it into
    // <tbody> at all.
    expect(substr_count($body, '<tr'))->toBe(1)
        // Five columns (Art, Betroffen, Anzahl, Zuletzt, Meldung): an unescaped pipe would
        // split the message into two <td> cells and inflate this count.
        ->and(substr_count($body, '<td'))->toBe(5)
        ->and($body)->toContain('fatal: could not read Username');
});

it('does not abort the run when one recipient throws: earlier sends stay marked, that one stays pending', function () {
    Mail::shouldReceive('to')->andReturnUsing(function (string $email) {
        return tap(Mockery::mock(PendingMail::class), function ($pending) use ($email) {
            if ($email === 'hooks@example.test') {
                $pending->shouldReceive('send')->andThrow(new RuntimeException('smtp exploded'));
            } else {
                $pending->shouldReceive('send')->andReturn(null);
            }
        });
    });

    $org = operatorOrgWithCadence('hourly');
    $syncEvent = recordFailure($org, 'sync.failed', 'acme/demo');
    $hookEvent = recordFailure($org, 'webhook.delivery_failed', 'https://hooks.example.test/a');
    subscriber($org, 'ops@example.test', ['sync.failed']);
    subscriber($org, 'hooks@example.test', ['webhook.delivery_failed']);

    // Must complete without throwing: one recipient's mailer failing is not the caller's
    // problem to handle, it is this job's problem to isolate and retry later.
    (new SendNotificationDigest)->handle();

    expect($syncEvent->fresh()->notified_at)->not->toBeNull()
        ->and($hookEvent->fresh()->notified_at)->toBeNull()
        ->and($org->fresh()->last_digest_sent_at)->not->toBeNull();
});

it('leaves every row pending when the only recipient throws, without erroring the run', function () {
    Mail::shouldReceive('to')->andReturnUsing(function (string $email) {
        return tap(Mockery::mock(PendingMail::class), function ($pending) {
            $pending->shouldReceive('send')->andThrow(new RuntimeException('smtp exploded'));
        });
    });

    $org = operatorOrgWithCadence('hourly');
    $event = recordFailure($org);
    subscriber($org, 'ops@example.test', ['sync.failed']);

    (new SendNotificationDigest)->handle();

    expect($event->fresh()->notified_at)->toBeNull()
        ->and($org->fresh()->last_digest_sent_at)->toBeNull();
});

// Checking the recipient existence first and returning early, before the pending query
// runs, is what keeps a subscriberless organization's ever-growing backlog from being
// loaded into memory on every run. Asserting on the query log (rather than just on
// Mail::assertNothingSent(), which passes either way — an empty $recipients collection
// also sends nothing) is what makes this test actually exercise that ordering.
it('never touches notification_events when the organization has no enabled recipient', function () {
    Mail::fake();
    $org = operatorOrgWithCadence('hourly');
    recordFailure($org);
    // No recipient at all, not even a disabled one.

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    (new SendNotificationDigest)->handle();

    Mail::assertNothingSent();
    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'notification_events')))->toBeFalse();
});

it('implements ShouldBeUnique so a slow-running instance cannot overlap a newly dispatched one', function () {
    expect(new SendNotificationDigest)->toBeInstanceOf(ShouldBeUnique::class);
});

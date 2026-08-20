<?php

use App\Events\PackageSyncFailed;
use App\Events\WebhookDeliveryFailed;
use App\Models\NotificationEventRecord;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Webhook;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

it('records a sync failure against the operator organization', function () {
    // Both organizations exist here to reflect realistic production data (a non-operator
    // organization does exist alongside the operator one), not to pin down which lookup
    // the listener performs. `Organization::first()` compiles to a query with no `order
    // by`, and PostgreSQL's documentation states row order is unspecified without one —
    // so data fixtures alone cannot deterministically prove the lookup is filtered by
    // `is_operator`. The test below this one asserts that on the executed SQL instead.
    Organization::factory()->create(['is_operator' => false]);
    $operator = Organization::factory()->create(['is_operator' => true]);
    $package = Package::factory()->create(['name' => 'acme/demo']);

    PackageSyncFailed::dispatch($package, 'auth denied');

    $record = NotificationEventRecord::sole();
    expect($record->organization_id)->toBe($operator->id)
        ->and($record->type)->toBe('sync.failed')
        ->and($record->subject_label)->toBe('acme/demo')
        ->and($record->summary)->toBe('auth denied')
        ->and($record->notified_at)->toBeNull();
});

it('filters the operator lookup by is_operator rather than trusting row order', function () {
    // Deterministic regardless of database internals: this asserts on the SQL the
    // listener actually issues, not on which row a data fixture happens to return.
    Organization::factory()->create(['is_operator' => true]);
    $package = Package::factory()->create();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query;
    });

    PackageSyncFailed::dispatch($package, 'auth denied');

    $organizationLookup = collect($queries)->first(
        fn (QueryExecuted $query) => str_contains($query->sql, 'from "organizations"')
    );

    expect($organizationLookup)->not->toBeNull()
        ->and($organizationLookup->sql)->toContain('"is_operator" = ?')
        ->and($organizationLookup->bindings)->toBe([true]);
});

it('records a webhook delivery failure with the webhook url as its subject', function () {
    Organization::factory()->create(['is_operator' => true]);
    $webhook = Webhook::factory()->create(['url' => 'https://hooks.example.test/a']);

    WebhookDeliveryFailed::dispatch($webhook, 'sync.failed', '502 Bad Gateway');

    $record = NotificationEventRecord::sole();
    expect($record->type)->toBe('webhook.delivery_failed')
        ->and($record->subject_label)->toBe('https://hooks.example.test/a')
        ->and($record->summary)->toContain('502');
});

it('records nothing when no operator organization exists', function () {
    $package = Package::factory()->create();

    PackageSyncFailed::dispatch($package, 'auth denied');

    expect(NotificationEventRecord::count())->toBe(0);
});

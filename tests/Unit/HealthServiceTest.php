<?php

use App\Models\Upstream;
use App\Services\Health\HealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('reports healthy core services and reachable upstreams', function () {
    Http::fake(['https://packagist.example/*' => Http::response('ok', 200)]);
    Upstream::factory()->create(['url' => 'https://packagist.example/packages.json']);

    $checks = collect(app(HealthService::class)->checks())->keyBy('key');

    expect($checks['database']['ok'])->toBeTrue();
    expect($checks['cache']['ok'])->toBeTrue();
    expect($checks->has('queue'))->toBeTrue();
    expect($checks['storage']['ok'])->toBeTrue();

    $upstream = collect(app(HealthService::class)->checks())->firstWhere('key', 'upstream:'.Upstream::first()->id);
    expect($upstream['ok'])->toBeTrue();
});

it('marks an unreachable upstream as failed but does not throw', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('refused')]);
    Upstream::factory()->create(['url' => 'https://down.example/x']);

    $checks = collect(app(HealthService::class)->checks());
    $u = $checks->firstWhere('key', 'upstream:'.Upstream::first()->id);
    expect($u['ok'])->toBeFalse();
});

it('does not report an unreachable queue backend as healthy', function () {
    // The same silence pattern the broadcasting check had: the queue used to be `ok`
    // whenever no job had *already* failed — i.e. green precisely while nothing could
    // run at all, because a backend that cannot be reached also fails nothing.
    Queue::shouldReceive('size')->andThrow(new RuntimeException('Connection refused'));

    $queue = collect(app(HealthService::class)->checks())->firstWhere('key', 'queue');

    expect($queue['ok'])->toBeFalse()
        ->and($queue['detail'])->toContain('Connection refused');
});

it('reports the failed jobs count in the queue check detail', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
    ]);

    $queue = collect(app(HealthService::class)->checks())->firstWhere('key', 'queue');
    expect($queue['detail'])->toContain('1');
});

it('reports a red check when APP_URL names no host at all', function () {
    // The one case the normalisation cannot repair: with no host there is nothing to
    // build an allowlist from and nothing to pin a URL root to, so both controls stand
    // down. That state must be visible to the operator rather than silent.
    config(['app.url' => '']);

    $check = collect(app(HealthService::class)->checks())->firstWhere('key', 'app-url');

    expect($check)->not->toBeNull()
        ->and($check['ok'])->toBeFalse();
});

it('reports the application URL as healthy when it names a host without a scheme', function () {
    config(['app.url' => 'registry.example.test']);

    $check = collect(app(HealthService::class)->checks())->firstWhere('key', 'app-url');

    expect($check['ok'])->toBeTrue()
        ->and($check['detail'])->toContain('https://registry.example.test');
});

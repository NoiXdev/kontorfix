<?php

use App\Jobs\SyncPackage;
use App\Models\Package;
use Illuminate\Support\Facades\Queue;

it('dispatches a sync job only for packages that have a repository url', function () {
    Queue::fake();
    $withRepo = Package::factory()->create(['repository_url' => 'https://github.com/acme/widget.git']);
    Package::factory()->create(['repository_url' => null]);

    $this->artisan('packages:resync')->assertSuccessful();

    Queue::assertPushed(SyncPackage::class, 1);
    Queue::assertPushed(SyncPackage::class, fn (SyncPackage $job) => $job->package->is($withRepo));
});

it('does not dispatch a sync job for a publish-based package that only carries a reference repository url', function () {
    Queue::fake();
    $gitSourced = Package::factory()->create(['repository_url' => 'https://github.com/acme/widget.git']);
    Package::factory()->create([
        'type' => 'npm',
        'source_mode' => 'publish',
        'repository_url' => 'https://github.com/acme/reference-only.git',
    ]);

    $this->artisan('packages:resync')->assertSuccessful();

    // Exact count of 1, not just "pushed at least once": this is the control-removal check
    // for the isGitSourced() guard — dropping it would push 2 jobs instead of 1.
    Queue::assertPushed(SyncPackage::class, 1);
    Queue::assertPushed(SyncPackage::class, fn (SyncPackage $job) => $job->package->is($gitSourced));
});

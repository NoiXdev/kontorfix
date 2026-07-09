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

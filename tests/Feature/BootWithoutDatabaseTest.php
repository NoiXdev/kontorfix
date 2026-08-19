<?php

use App\Models\StorageSetting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

// Reading settings out of the database in a provider's boot() puts a query — and a
// fresh connection — into *every* application boot. In the test suite that is one
// connection per test (measured: ~1000 per run, up to 14 alive at once), and it runs
// before RefreshDatabase opens its transaction, so anything written there commits and
// outlives the test. Settings must therefore be applied when they are first needed,
// not while the container is still coming up.

it('boots the application without touching the database', function () {
    $app = require base_path('bootstrap/app.php');

    $queries = [];
    $app->booting(function ($app) use (&$queries): void {
        $app->make('db')->listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
    });

    $app->make(Kernel::class)->bootstrap();

    expect($queries)->toBe([]);
});

it('leaves the environment-configured mailer in place until mail is used', function () {
    expect(config('mail.default'))->toBe('array');
});

it('applies the persisted storage settings the first time the filesystem is used', function () {
    StorageSetting::query()->delete();
    StorageSetting::query()->create([
        'driver' => 's3',
        'bucket' => 'artifacts-bucket',
        'region' => 'eu-central-1',
        'key' => 'k',
        'secret' => 's',
    ]);

    expect(config('filesystems.disks.artifacts.driver'))->toBe('local');

    Storage::forgetDisk('artifacts');
    app('filesystem');

    expect(config('filesystems.disks.artifacts.driver'))->toBe('s3')
        ->and(config('filesystems.disks.artifacts.bucket'))->toBe('artifacts-bucket');
});

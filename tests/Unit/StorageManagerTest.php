<?php

use App\Models\StorageSetting;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a local disk config by default', function () {
    $config = app(StorageManager::class)->diskConfig();
    expect($config['driver'])->toBe('local');
    expect($config['root'])->toContain('artifacts');
});

it('builds an s3 disk config from the stored setting', function () {
    StorageSetting::current()->update([
        'driver' => 's3', 'key' => 'AKIA', 'secret' => 'shh', 'region' => 'eu-central-1',
        'bucket' => 'kontorfix', 'endpoint' => 'https://minio.test', 'use_path_style' => true,
    ]);

    $config = app(StorageManager::class)->diskConfig();
    expect($config['driver'])->toBe('s3')
        ->and($config['key'])->toBe('AKIA')
        ->and($config['secret'])->toBe('shh')
        ->and($config['bucket'])->toBe('kontorfix')
        ->and($config['endpoint'])->toBe('https://minio.test')
        ->and($config['use_path_style_endpoint'])->toBeTrue();

    $raw = DB::table('storage_settings')->value('secret');
    expect($raw)->not->toBe('shh');
});

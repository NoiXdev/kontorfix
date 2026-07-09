<?php

use App\Models\StorageSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('resolves the artifacts disk and can round-trip a file', function () {
    StorageSetting::current();
    Storage::forgetDisk('artifacts');
    Storage::disk('artifacts')->put('probe.txt', 'hello');
    expect(Storage::disk('artifacts')->get('probe.txt'))->toBe('hello');
    Storage::disk('artifacts')->delete('probe.txt');
});

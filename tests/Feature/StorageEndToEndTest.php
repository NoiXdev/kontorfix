<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\StorageSetting;
use App\Models\User;
use App\Services\Storage\StorageManager;
use Illuminate\Support\Facades\Storage;

it('lets an admin configure local storage and then round-trips an artifact', function () {
    $admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);

    // Set to the local driver via the GUI.
    $this->actingAs($admin)->put('/admin/storage', ['driver' => 'local'])->assertRedirect();
    expect(StorageSetting::current()->driver)->toBe('local');

    // Connection test reports success.
    $this->actingAs($admin)->postJson('/admin/storage/test', ['driver' => 'local'])
        ->assertOk()->assertJsonPath('ok', true);

    // A real artifact write/read through the configured artifacts disk works.
    config(['filesystems.disks.artifacts' => app(StorageManager::class)->diskConfig()]);
    Storage::forgetDisk('artifacts');
    Storage::disk('artifacts')->put('e2e/probe.txt', 'payload');
    expect(Storage::disk('artifacts')->get('e2e/probe.txt'))->toBe('payload');
    Storage::disk('artifacts')->delete('e2e/probe.txt');
});

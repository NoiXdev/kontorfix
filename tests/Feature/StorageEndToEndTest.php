<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\StorageSetting;
use App\Models\User;
use App\Services\Storage\StorageManager;
use Illuminate\Support\Facades\Storage;

it('lets an admin configure local storage and then round-trips an artifact', function () {
    $admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);

    // Über die GUI auf lokalen Treiber setzen.
    $this->actingAs($admin)->put('/admin/storage', ['driver' => 'local'])->assertRedirect();
    expect(StorageSetting::current()->driver)->toBe('local');

    // Verbindungstest meldet Erfolg.
    $this->actingAs($admin)->postJson('/admin/storage/test', ['driver' => 'local'])
        ->assertOk()->assertJsonPath('ok', true);

    // Ein realer Artefakt-Schreib-/Lesevorgang über die konfigurierte artifacts-Disk klappt.
    config(['filesystems.disks.artifacts' => app(StorageManager::class)->diskConfig()]);
    Storage::forgetDisk('artifacts');
    Storage::disk('artifacts')->put('e2e/probe.txt', 'payload');
    expect(Storage::disk('artifacts')->get('e2e/probe.txt'))->toBe('payload');
    Storage::disk('artifacts')->delete('e2e/probe.txt');
});

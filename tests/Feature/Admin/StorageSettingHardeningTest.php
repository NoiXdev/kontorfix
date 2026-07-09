<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\StorageSetting;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
});

it('forbids maintainers from updating or testing storage', function () {
    $maint = User::factory()->for(Organization::factory())->create(['role' => UserRole::Maintainer]);

    $this->actingAs($maint)->put('/admin/storage', ['driver' => 'local'])->assertForbidden();
    $this->actingAs($maint)->postJson('/admin/storage/test', ['driver' => 'local'])->assertForbidden();
});

it('tests the submitted config, not the saved one, and does not persist it', function () {
    // Gespeichert ist lokal (Default).
    StorageSetting::current();

    // Ein s3-Config mit unerreichbarem Endpoint wird getestet → ok:false.
    $res = $this->actingAs($this->admin)->postJson('/admin/storage/test', [
        'driver' => 's3', 'key' => 'AKIA', 'secret' => 'sec', 'region' => 'eu',
        'bucket' => 'b', 'endpoint' => 'https://minio.invalid',
    ]);
    $res->assertOk()->assertJsonPath('ok', false);

    // Der Test hat NICHTS gespeichert — die aktive Config bleibt lokal.
    expect(StorageSetting::current()->driver)->toBe('local');
});

it('requires a secret when first switching to s3', function () {
    // Noch kein Secret hinterlegt → Secret ist Pflicht.
    $this->actingAs($this->admin)->put('/admin/storage', [
        'driver' => 's3', 'key' => 'AKIA', 'region' => 'eu', 'bucket' => 'b',
    ])->assertSessionHasErrors('secret');

    expect(StorageSetting::current()->driver)->toBe('local');
});

it('accepts an s3 switch that includes a secret', function () {
    $this->actingAs($this->admin)->put('/admin/storage', [
        'driver' => 's3', 'key' => 'AKIA', 'secret' => 'sec', 'region' => 'eu', 'bucket' => 'b',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $s = StorageSetting::current();
    expect($s->driver)->toBe('s3')->and($s->secret)->toBe('sec');
});

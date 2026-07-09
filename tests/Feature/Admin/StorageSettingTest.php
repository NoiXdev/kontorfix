<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\StorageSetting;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
});

it('shows the current storage settings without exposing the secret', function () {
    StorageSetting::current()->update(['driver' => 's3', 'secret' => 'shh', 'bucket' => 'b']);
    $this->actingAs($this->admin)->get('/admin/storage')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/storage/Index')
            ->where('settings.driver', 's3')->where('settings.has_secret', true)->missing('settings.secret'));
});

it('forbids maintainers', function () {
    $m = User::factory()->for(Organization::factory())->create(['role' => UserRole::Maintainer]);
    $this->actingAs($m)->get('/admin/storage')->assertForbidden();
});

it('updates to s3 and keeps the existing secret when left blank', function () {
    StorageSetting::current()->update(['driver' => 's3', 'secret' => 'keep-me']);

    $this->actingAs($this->admin)->put('/admin/storage', [
        'driver' => 's3', 'key' => 'AKIA', 'region' => 'eu', 'bucket' => 'kontorfix',
        'endpoint' => 'https://minio.test', 'use_path_style' => true,
    ])->assertRedirect();

    $s = StorageSetting::current();
    expect($s->bucket)->toBe('kontorfix')->and($s->secret)->toBe('keep-me');
});

it('runs a connection test for the local driver', function () {
    $this->actingAs($this->admin)->postJson('/admin/storage/test', ['driver' => 'local'])
        ->assertOk()->assertJsonPath('ok', true);
});

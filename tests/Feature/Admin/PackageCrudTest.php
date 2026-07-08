<?php

use App\Enums\UserRole;
use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('lists packages for admins', function () {
    Package::factory()->count(2)->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->get('/admin/packages')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/Index')->has('packages.data', 2));
});

it('creates a package, assigns groups inline and dispatches sync', function () {
    Queue::fake();
    $groups = Group::factory()->count(2)->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->post('/admin/packages', [
            'type' => 'composer',
            'repository_url' => 'https://git.example.com/acme/demo.git',
            'name' => 'acme/demo',
            'group_ids' => $groups->pluck('id')->all(),
        ])->assertRedirect();

    $pkg = Package::where('name', 'acme/demo')->firstOrFail();
    expect($pkg->groups)->toHaveCount(2);
    Queue::assertPushed(SyncPackage::class);
});

it('validates package name format and uniqueness', function () {
    Package::factory()->create(['type' => 'composer', 'name' => 'acme/demo']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->post('/admin/packages', ['type' => 'composer', 'name' => 'Invalid Name', 'repository_url' => 'x'])
        ->assertSessionHasErrors('name');
    $this->actingAs($admin)->post('/admin/packages', ['type' => 'composer', 'name' => 'acme/demo', 'repository_url' => 'x'])
        ->assertSessionHasErrors('name');
});

it('forbids members from managing packages', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->get('/admin/packages')->assertForbidden();
});

it('deletes a package', function () {
    $pkg = Package::factory()->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->delete("/admin/packages/{$pkg->id}")->assertRedirect();
    expect(Package::find($pkg->id))->toBeNull();
});

<?php

use App\Enums\UserRole;
use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('lists packages for admins', function () {
    Package::factory()->count(2)->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->get('/admin/packages')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/Index')->has('packages.data', 2));
});

it('creates a package, assigns groups inline and dispatches sync', function () {
    Queue::fake();
    $groups = Group::factory()->count(2)->create();

    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
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

it('returns the created package as json for inline picker creation', function () {
    Queue::fake();

    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->postJson('/admin/packages', [
            'type' => 'npm',
            'name' => '@acme/widget',
            'repository_url' => 'https://git.example.com/acme/widget.git',
        ])
        ->assertCreated()
        ->assertJson(['name' => '@acme/widget', 'type' => 'npm'])
        ->assertJsonStructure(['id', 'name', 'type']);

    Queue::assertPushed(SyncPackage::class);
    expect(Package::where('name', '@acme/widget')->exists())->toBeTrue();
});

it('returns json validation errors for inline picker creation', function () {
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->postJson('/admin/packages', [
            'type' => 'composer',
            'name' => 'Invalid Name',
            'repository_url' => 'file:///etc/passwd',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'repository_url']);
});

it('validates package name format and uniqueness', function () {
    Package::factory()->create(['type' => 'composer', 'name' => 'acme/demo']);
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    $url = 'https://git.example.com/acme/demo.git';
    $this->actingAs($admin)->post('/admin/packages', ['type' => 'composer', 'name' => 'Invalid Name', 'repository_url' => $url])
        ->assertSessionHasErrors('name');
    $this->actingAs($admin)->post('/admin/packages', ['type' => 'composer', 'name' => 'acme/demo', 'repository_url' => $url])
        ->assertSessionHasErrors('name');
});

it('rejects non-https/ssh repository urls to avoid ssrf', function () {
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    foreach (['file:///etc/passwd', 'http://internal.svc/repo.git', 'gopher://x', 'not-a-url'] as $bad) {
        $this->actingAs($admin)->post('/admin/packages', ['type' => 'composer', 'name' => 'acme/demo', 'repository_url' => $bad])
            ->assertSessionHasErrors('repository_url');
    }

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'composer', 'name' => 'acme/demo', 'repository_url' => 'ssh://git@github.com/acme/demo.git',
    ])->assertSessionDoesntHaveErrors('repository_url');
});

it('drops sync status injection on create (mass assignment guard)', function () {
    Queue::fake();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->post('/admin/packages', [
            'type' => 'composer',
            'name' => 'acme/forged',
            'repository_url' => 'https://git.example.com/acme/forged.git',
            'sync_status' => 'synced',
            'sync_error' => 'x',
        ])->assertRedirect();

    // The injected synced status must not get through — the sync job sets it.
    expect(Package::where('name', 'acme/forged')->first()->sync_status->value)->toBe('pending');
});

it('forbids members but allows admins and maintainers', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->get('/admin/packages')->assertForbidden();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Maintainer]))
        ->get('/admin/packages')->assertOk();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->get('/admin/packages')->assertOk();
});

it('deletes a package', function () {
    $pkg = Package::factory()->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->delete("/admin/packages/{$pkg->id}")->assertRedirect();
    expect(Package::find($pkg->id))->toBeNull();
});

it('forbids members from creating packages via the json path', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->postJson('/admin/packages', [
            'type' => 'composer',
            'name' => 'acme/sneaky',
            'repository_url' => 'https://git.example.com/acme/sneaky.git',
        ])->assertForbidden();

    expect(Package::where('name', 'acme/sneaky')->exists())->toBeFalse();
});

it('accepts a scoped npm package name but still requires vendor/name for composer', function () {
    Queue::fake();
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    $url = 'https://git.example.com/acme/demo.git';

    // npm: scoped name allowed
    $this->actingAs($admin)->post('/admin/packages', ['type' => 'npm', 'name' => '@noixdev/ui-kit', 'repository_url' => $url])
        ->assertRedirect()->assertSessionHasNoErrors();
    // npm: bare name allowed
    $this->actingAs($admin)->post('/admin/packages', ['type' => 'npm', 'name' => 'leftpad', 'repository_url' => $url])
        ->assertSessionHasNoErrors();
    // composer: bare name (without vendor/) rejected
    $this->actingAs($admin)->post('/admin/packages', ['type' => 'composer', 'name' => 'leftpad', 'repository_url' => $url])
        ->assertSessionHasErrors('name');
    // npm: uppercase letters rejected
    $this->actingAs($admin)->post('/admin/packages', ['type' => 'npm', 'name' => '@noixdev/UI-Kit', 'repository_url' => $url])
        ->assertSessionHasErrors('name');
});

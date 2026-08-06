<?php

use App\Enums\ApiKeyPermission;
use App\Enums\PackageType;
use App\Enums\UserRole;
use App\Jobs\SyncPackage;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function operatorAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('creates a Python package without a repository and dispatches no sync', function () {
    Queue::fake();

    $this->actingAs(operatorAdmin())->post('/admin/packages', [
        'type' => 'python', 'name' => 'My.Package',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $pkg = Package::where('name', 'My.Package')->first();
    expect($pkg)->not->toBeNull()
        ->and($pkg->type)->toBe(PackageType::Python)
        ->and($pkg->repository_url)->toBeNull();

    // Publish-based, so nothing to sync from git.
    Queue::assertNotPushed(SyncPackage::class);
});

it('validates the Python project name (PEP 508 shape)', function () {
    $admin = operatorAdmin();

    $this->actingAs($admin)->post('/admin/packages', ['type' => 'python', 'name' => 'has spaces'])
        ->assertSessionHasErrors('name');
    $this->actingAs($admin)->post('/admin/packages', ['type' => 'python', 'name' => 'valid_name.0'])
        ->assertSessionHasNoErrors();
});

it('still requires a repository for git-synced composer packages', function () {
    $this->actingAs(operatorAdmin())->post('/admin/packages', ['type' => 'composer', 'name' => 'acme/lib'])
        ->assertSessionHasErrors('repository_url');
});

it('creates a Python package via the API without a repository', function () {
    Queue::fake();
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($op)->create(['role' => UserRole::Admin]);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    $this->withToken($plain)->postJson('/api/v1/packages', [
        'type' => 'python', 'name' => 'demo-lib',
    ])->assertCreated()->assertJsonPath('data.type', 'python');

    Queue::assertNotPushed(SyncPackage::class);
});

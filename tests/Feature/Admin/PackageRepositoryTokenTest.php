<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function tokenAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('stores the repository token encrypted and never serialises it', function () {
    Queue::fake();

    $this->actingAs(tokenAdmin())->post('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/private',
        'repository_url' => 'https://github.com/acme/private.git',
        'repository_token' => 'ghp_supersecret',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $package = Package::where('name', 'acme/private')->firstOrFail();

    // Decrypts transparently through the model...
    expect($package->repository_token)->toBe('ghp_supersecret');

    // ...but the raw column is ciphertext, and the token is hidden from serialisation.
    $raw = DB::table('packages')->where('id', $package->id)->value('repository_token');
    expect($raw)->not->toBe('ghp_supersecret')
        ->and($package->toArray())->not->toHaveKey('repository_token');
});

it('accepts an optional repository token in the probe request', function () {
    // A malformed URL is still rejected even with a token present (token is optional).
    $this->actingAs(tokenAdmin())->postJson('/admin/packages/probe', [
        'type' => 'composer',
        'repository_url' => 'ftp://nope',
        'repository_token' => 'ghp_x',
    ])->assertStatus(422);
});

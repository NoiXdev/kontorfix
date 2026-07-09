<?php

use App\Models\User;
use Laravel\Passkeys\Passkey;

function makePasskey(User $user, string $name = 'Key'): Passkey
{
    return Passkey::forceCreate([
        'user_id' => $user->getKey(),
        'name' => $name,
        'credential_id' => 'cred-'.$user->getKey().'-'.$name,
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
}

it('shows the settings page with the users own passkeys', function () {
    $user = User::factory()->create();
    makePasskey($user, 'MacBook');
    makePasskey(User::factory()->create(), 'Someone else');

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get('/settings/passkeys')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('settings/Passkeys')
            ->has('passkeys', 1)
            ->where('passkeys.0.name', 'MacBook'));
});

it('requires password confirmation to view the passkey settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/passkeys')->assertRedirect(route('password.confirm'));
});

it('lets a user delete only their own passkey', function () {
    $user = User::factory()->create();
    $own = makePasskey($user, 'Mine');
    $foreign = makePasskey(User::factory()->create(), 'Theirs');

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->delete("/user/passkeys/{$foreign->id}")->assertForbidden();
    expect(Passkey::find($foreign->id))->not->toBeNull();

    $this->delete("/user/passkeys/{$own->id}");
    expect(Passkey::find($own->id))->toBeNull();
});

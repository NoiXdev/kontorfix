<?php

// A gate that some legitimate account types can never satisfy is a lockout, not a
// control. OIDC-provisioned and admin-invited users hold a password nobody knows
// (`bcrypt(Str::random(40))`), and a passkey-only user should not be asked for one at
// all. The confirm-password screen therefore has to offer both alternatives: confirming
// with an enrolled passkey, and mailing the owner a link to set a password.

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkey;

it('tells the confirm-password screen which alternatives the account has', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/confirm-password')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/ConfirmPassword')
            ->where('canUsePasskey', false)
            ->where('canRequestPasswordLink', true)
        );

    Passkey::forceCreate([
        'user_id' => $user->getKey(),
        'name' => 'MacBook',
        'credential_id' => 'cred-'.$user->getKey(),
        'credential' => ['foo' => 'bar'],
    ]);

    $this->actingAs($user->fresh())->get('/confirm-password')
        ->assertInertia(fn ($page) => $page->where('canUsePasskey', true));
});

it('mails the session owner a link to set a password they can then confirm with', function () {
    Notification::fake();

    // An OIDC-provisioned account: real mailbox, password nobody knows.
    $user = User::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'password' => bcrypt(Str::random(40)),
    ]);

    $this->actingAs($user)->from('/confirm-password')
        ->post('/confirm-password/set-link')
        ->assertRedirect('/confirm-password')
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('ignores any address the caller supplies and only ever mails the session owner', function () {
    Notification::fake();

    $user = User::factory()->create();
    $victim = User::factory()->create();

    $this->actingAs($user)->from('/confirm-password')
        ->post('/confirm-password/set-link', ['email' => $victim->email])
        ->assertRedirect('/confirm-password');

    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertNotSentTo($victim, ResetPassword::class);
});

it('does not fail when the account has no mailbox to send to', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => null]);

    $this->actingAs($user)->from('/confirm-password')
        ->post('/confirm-password/set-link')
        ->assertRedirect('/confirm-password');

    Notification::assertNothingSent();
});

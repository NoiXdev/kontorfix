<?php

// `POST /forgot-password` is deliberately written to answer the same way whether or not
// the address is registered. `POST /reset-password` gave the bit away anyway: the broker
// returns INVALID_USER *before* it ever consults the token, and the controller rendered
// the status verbatim. Anonymous, no token, one bit per address, against the customer
// directory of a multi-tenant registry.

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

function resetFailureMessage(string $email): string
{
    $response = test()->post('/reset-password', [
        'token' => str_repeat('a', 64),
        'email' => $email,
        'password' => 'new-password-1234',
        'password_confirmation' => 'new-password-1234',
    ]);

    $response->assertSessionHasErrors('email');

    return (string) session('errors')->first('email');
}

it('answers identically for a registered and an unregistered address', function () {
    $user = User::factory()->create(['email' => 'known@example.com']);

    $known = resetFailureMessage($user->email);
    $this->flushSession();
    $unknown = resetFailureMessage('nobody-here@example.com');

    expect($unknown)->toBe($known)
        ->and($unknown)->toBe(__('passwords.token'));
});

it('still lets a real token through', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-1234',
        'password_confirmation' => 'new-password-1234',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect(Hash::check('new-password-1234', $user->fresh()->password))->toBeTrue();
});

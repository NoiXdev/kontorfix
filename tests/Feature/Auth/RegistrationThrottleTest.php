<?php

// `POST /register` was the one unauthenticated endpoint on the instance with no limit at
// all, and one of the most expensive: a bcrypt-12 hash, a row insert and a `Registered`
// event that sends mail, per request. Off by default, so this only bites instances that
// deliberately opened registration — which is exactly the shape nobody watches.

use App\Models\SystemSetting;
use App\Models\User;

function openRegistration(): void
{
    SystemSetting::current()->forceFill(['registration_enabled' => true])->save();
}

/** @return array<string, string> */
function registrationPayload(int $n): array
{
    return [
        'name' => "New User {$n}",
        'email' => "new-user-{$n}@example.com",
        'password' => 'a-perfectly-fine-password',
        'password_confirmation' => 'a-perfectly-fine-password',
    ];
}

it('refuses a sixth registration in a minute from one source address', function () {
    $this->instanceAlreadySetUp();
    openRegistration();

    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->post('/register', registrationPayload($i))
            ->assertRedirect();

        $this->post('/logout');
        $this->flushSession();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->post('/register', registrationPayload(6))
        ->assertStatus(429);

    expect(User::where('email', 'new-user-6@example.com')->exists())->toBeFalse();
});

it('keys the limit on the source address, so one abuser does not close registration', function () {
    $this->instanceAlreadySetUp();
    openRegistration();

    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->post('/register', registrationPayload($i));
        $this->post('/logout');
        $this->flushSession();
    }

    // Reachability anchor as well as the property: the sixth attempt from a *different*
    // address succeeds, so the 429 above is `throttle:5,1` and not the `guest` middleware,
    // EnsureRegistrationEnabled (which answers 403), CSRF or validation — none of which
    // would care which address the request came from.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->post('/register', registrationPayload(6))
        ->assertRedirect();

    expect(User::where('email', 'new-user-6@example.com')->exists())->toBeTrue();
});

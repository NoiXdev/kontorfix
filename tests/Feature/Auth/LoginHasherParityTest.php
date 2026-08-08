<?php

// The login path burns a hash when no account matches, so the response time does not say
// whether the address is registered. That defence has to be built from the *active* hasher:
// this project ships no config/hashing.php, so HASH_DRIVER and BCRYPT_ROUNDS are live env
// knobs, and a pinned `$2y$12$…` literal desynchronises from real hashes the moment either
// moves — replacing a timing oracle with a deterministic one.

use App\Models\User;
use Database\Factories\UserFactory;

/** UserFactory caches its hash in a static, so a driver switch must not outlive the test. */
function forgetFactoryPassword(): void
{
    (function () {
        static::$password = null;
    })->bindTo(null, UserFactory::class)();
}

afterEach(fn () => forgetFactoryPassword());

it('does not fail an unknown account differently from a known one under argon2id', function () {
    config(['hashing.driver' => 'argon2id']);
    forgetFactoryPassword();

    $user = User::factory()->create();

    // A bcrypt literal handed to ArgonHasher::check() raises RuntimeException, so a missing
    // account would answer 500 while a real one answers 302 — one request, no timing needed.
    $missing = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'password']);
    $known = $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    $missing->assertStatus(302)->assertSessionHasErrors('email');
    $known->assertStatus(302)->assertSessionHasErrors('email');
    $this->assertGuest();

    // And the account itself still works, i.e. the parity is between two live branches
    // rather than between two broken ones.
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

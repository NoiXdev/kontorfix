<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    // The global test broadcaster is "null", whose auth() is a no-op and never
    // verifies channel access (it would return 200 for everyone). Switch to the
    // reverb (Pusher-based) broadcaster with throwaway credentials so the channel
    // authorization is actually enforced (HMAC signing only, no network). Channel
    // definitions are registered on the default driver at boot, so re-load the
    // real routes/channels.php onto the freshly resolved reverb broadcaster.
    config()->set([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    require base_path('routes/channels.php');
});

/** @param  User  $user */
function authorizeUserChannel($user, string $targetId): TestResponse
{
    return test()->actingAs($user)->post('/broadcasting/auth', [
        'channel_name' => 'private-App.Models.User.'.$targetId,
        'socket_id' => '123.456',
    ]);
}

it('authorizes a user on their own private channel', function () {
    $user = User::factory()->for(Organization::factory())->create();

    authorizeUserChannel($user, $user->id)->assertOk();
});

/**
 * Regression: the callback compared `(int) $user->id === (int) $id`. All models use
 * UUIDv7 primary keys, and PHP's int cast stops at the first non-digit, so every UUID
 * collapsed to the same leading number and the callback authorized any user for any
 * other user's channel.
 */
it('denies a user another user\'s private channel', function () {
    $user = User::factory()->for(Organization::factory())->create();
    $victim = User::factory()->for(Organization::factory())->create();

    // Two UUIDv7 keys generated in the same millisecond range share the leading digits
    // that the old int cast looked at — the exact collision the fix removes.
    authorizeUserChannel($user, $victim->id)->assertForbidden();
    authorizeUserChannel($user, 'not-a-uuid')->assertForbidden();
});

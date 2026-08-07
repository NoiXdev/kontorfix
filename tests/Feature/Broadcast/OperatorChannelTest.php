<?php

use App\Enums\UserRole;
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
function authorizeOperatorChannel($user): TestResponse
{
    return test()->actingAs($user)->post('/broadcasting/auth', [
        'channel_name' => 'private-operator',
        'socket_id' => '123.456',
    ]);
}

/**
 * The events on this channel are instance-wide (every organization's packages,
 * including raw sync error text), so the predicate has to be the instance-wide role:
 * super-admin. Operator-org maintainers and members are scoped to their own
 * organization everywhere else and must not receive other tenants' sync events.
 */
it('authorizes super-admins on the private operator channel', function () {
    $operatorAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
    $flaggedSuperAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Member, 'is_super_admin' => true]);

    authorizeOperatorChannel($operatorAdmin)->assertOk();
    authorizeOperatorChannel($flaggedSuperAdmin)->assertOk();
});

it('denies the private operator channel to everyone below super-admin', function () {
    $operatorOrg = Organization::factory()->create(['is_operator' => true]);
    $operatorMaintainer = User::factory()->for($operatorOrg)->create(['role' => UserRole::Maintainer]);
    $operatorMember = User::factory()->for($operatorOrg)->create(['role' => UserRole::Member]);
    $customerAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    $customer = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Member]);

    authorizeOperatorChannel($operatorMaintainer)->assertForbidden();
    authorizeOperatorChannel($operatorMember)->assertForbidden();
    authorizeOperatorChannel($customerAdmin)->assertForbidden();
    authorizeOperatorChannel($customer)->assertForbidden();
});

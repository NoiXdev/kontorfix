<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

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

it('authorizes only operator-org users on the private operator channel', function () {
    $operatorAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
    $operatorMaintainer = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Maintainer]);
    $customer = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Member]);

    $this->actingAs($operatorAdmin)->post('/broadcasting/auth', ['channel_name' => 'private-operator', 'socket_id' => '123.456'])->assertOk();
    $this->actingAs($operatorMaintainer)->post('/broadcasting/auth', ['channel_name' => 'private-operator', 'socket_id' => '123.456'])->assertOk();
    $this->actingAs($customer)->post('/broadcasting/auth', ['channel_name' => 'private-operator', 'socket_id' => '123.456'])->assertForbidden();
});

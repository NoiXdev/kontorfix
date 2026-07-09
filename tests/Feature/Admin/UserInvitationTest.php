<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
});

it('sends an invitation when no password is given', function () {
    Notification::fake();
    $cust = Organization::factory()->create();

    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'Neu', 'email' => 'neu@kunde.test', 'organization_id' => $cust->id, 'role' => 'member',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $user = User::where('email', 'neu@kunde.test')->firstOrFail();
    Notification::assertSentTo($user, UserInvitation::class);
});

it('sets the password directly when one is given (no invitation)', function () {
    Notification::fake();
    $cust = Organization::factory()->create();

    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'Direkt', 'email' => 'direkt@kunde.test', 'organization_id' => $cust->id, 'role' => 'member', 'password' => 'geheim-1234',
    ])->assertRedirect();

    $user = User::where('email', 'direkt@kunde.test')->firstOrFail();
    expect(Hash::check('geheim-1234', $user->password))->toBeTrue();
    Notification::assertNothingSentTo($user);
});

it('can resend an invitation to an existing user', function () {
    Notification::fake();
    $user = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);

    $this->actingAs($this->admin)->post("/admin/users/{$user->id}/invite")->assertRedirect();
    Notification::assertSentTo($user, UserInvitation::class);
});

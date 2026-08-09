<?php

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Notification;

// `dashboard` and `portal/*` carry the `verified` middleware, but User did not implement
// MustVerifyEmail, so the gate always fell through and the whole verify-email surface —
// routes, controllers and the resend UI on the profile page — was dead code.

it('declares the user model as requiring email verification', function () {
    expect(User::factory()->create())->toBeInstanceOf(MustVerifyEmail::class);
});

it('bounces an unverified user off the dashboard to the verification notice', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
});

it('bounces an unverified user off the portal', function () {
    $user = User::factory()->unverified()->create([
        'organization_id' => Organization::factory()->create()->id,
    ]);

    $this->actingAs($user)->get('/portal')->assertRedirect(route('verification.notice'));
});

it('lets a verified user through to the dashboard', function () {
    // Members are redirected on to the portal, so this needs a non-member role.
    $user = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

it('sends a verification mail on self-registration', function () {
    Notification::fake();
    $this->instanceAlreadySetUp();
    SystemSetting::current()->update(['registration_enabled' => true]);

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'fresh@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'fresh@example.com')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('leaves invited users verified, so the invitation flow needs no verification step', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($operator)->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'Invited',
        'email' => 'invited@example.com',
        'organization_id' => $operator->id,
        'role' => 'member',
        'password' => 'geheim-1234',
    ])->assertRedirect();

    expect(User::where('email', 'invited@example.com')->firstOrFail()->hasVerifiedEmail())->toBeTrue();
});

it('never treats a robot account as unverified', function () {
    $robot = User::factory()->create([
        'account_type' => AccountType::Robot,
        'email' => null,
    ]);
    // Even with the column explicitly cleared — a robot has no mailbox to verify.
    $robot->forceFill(['email_verified_at' => null])->save();

    expect($robot->fresh()->hasVerifiedEmail())->toBeTrue();
});

<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Models\User;

function systemAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('shows the system settings to an operator admin', function () {
    $this->actingAs(systemAdmin())->get('/admin/system')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/system/Index')->has('settings.registration_enabled'));
});

it('toggles self-registration', function () {
    expect(SystemSetting::current()->registration_enabled)->toBeFalse();

    $this->actingAs(systemAdmin())->put('/admin/system', ['registration_enabled' => true])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(SystemSetting::current()->registration_enabled)->toBeTrue();
});

it('denies system settings to non-operator admins', function () {
    $outsider = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);

    $this->actingAs($outsider)->get('/admin/system')->assertForbidden();
    $this->actingAs($outsider)->put('/admin/system', ['registration_enabled' => true])->assertForbidden();
});

it('exposes the registration flag to the frontend for the login page', function () {
    SystemSetting::current()->update(['registration_enabled' => true]);
    User::factory()->create();

    $this->get('/login')->assertInertia(fn ($page) => $page->where('registrationEnabled', true));
});

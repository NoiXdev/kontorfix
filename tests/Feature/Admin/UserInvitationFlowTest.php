<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Password;

it('onboards an invited user who sets their own password and logs in', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($operator)->create(['role' => UserRole::Admin]);
    $cust = Organization::factory()->create();

    // Einladen (ohne Passwort)
    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'Kunde', 'email' => 'invite@kunde.test', 'organization_id' => $cust->id, 'role' => 'member',
    ])->assertRedirect();
    $user = User::where('email', 'invite@kunde.test')->firstOrFail();

    // Token wie in der Einladung erzeugen und Passwort setzen (nutzt NewPasswordController)
    $token = Password::broker()->createToken($user);
    $this->post('/logout');
    $this->post('/reset-password', [
        'token' => $token, 'email' => 'invite@kunde.test',
        'password' => 'neues-geheim-1234', 'password_confirmation' => 'neues-geheim-1234',
    ])->assertSessionHasNoErrors();

    // Einloggen mit dem selbst gesetzten Passwort
    $this->post('/logout');
    $this->post('/login', ['email' => 'invite@kunde.test', 'password' => 'neues-geheim-1234'])
        ->assertRedirect();
    $this->assertAuthenticatedAs($user->fresh());
});

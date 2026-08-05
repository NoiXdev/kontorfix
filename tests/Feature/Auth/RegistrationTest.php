<?php

namespace Tests\Feature\Auth;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function enableRegistration(): void
    {
        SystemSetting::current()->update(['registration_enabled' => true]);
    }

    public function test_registration_screen_can_be_rendered()
    {
        $this->instanceAlreadySetUp();
        $this->enableRegistration();

        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register()
    {
        // Self-registration is only reachable once the instance has an admin — the
        // very first account must come from the setup wizard, never from /register.
        $this->instanceAlreadySetUp();
        $this->enableRegistration();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_screen_is_blocked_when_disabled()
    {
        // Registration is disabled by default — the page redirects to login.
        $this->instanceAlreadySetUp();

        $this->get('/register')->assertRedirect(route('login'));
    }

    public function test_registration_post_is_refused_when_disabled()
    {
        $this->instanceAlreadySetUp();

        $this->post('/register', [
            'name' => 'Blocked User',
            'email' => 'blocked@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertForbidden();

        $this->assertGuest();
    }
}

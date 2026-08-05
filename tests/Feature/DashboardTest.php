<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        // Without this the instance is still un-set-up, and RequireSetup sends the
        // guest to the wizard instead of the login page.
        $this->instanceAlreadySetUp();

        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        // The dashboard is the operator/maintainer screen; members are redirected
        // to the customer portal (see routes/web.php), hence a non-member role here.
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertStatus(200);
    }
}

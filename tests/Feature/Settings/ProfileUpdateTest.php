<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/settings/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        Notification::fake();
        // A transport that actually delivers: the new address is unproven, so it loses its
        // verified stamp and immediately gets a fresh challenge.
        config(['mail.default' => 'smtp']);

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_the_email_stays_verified_when_the_instance_cannot_deliver_mail()
    {
        Notification::fake();
        // The setup wizard's default. Clearing the stamp here would gate the dashboard and
        // the portal behind a challenge the instance is incapable of delivering.
        config(['mail.default' => 'log']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => $user->name, 'email' => 'moved@example.com'])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('moved@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->actingAs($user)->get('/dashboard')->assertRedirectContains('portal');
    }

    public function test_a_failing_mailer_rolls_the_email_change_back_instead_of_locking_the_user_out()
    {
        config(['mail.default' => 'smtp']);

        $this->mock(NotificationDispatcher::class)
            ->shouldReceive('send')->andThrow(new RuntimeException('smtp unreachable'))
            ->shouldReceive('sendNow')->andThrow(new RuntimeException('smtp unreachable'));

        $user = User::factory()->create();
        $original = $user->email;

        $this->actingAs($user)
            ->from('/settings/profile')
            ->patch('/settings/profile', ['name' => $user->name, 'email' => 'moved@example.com'])
            ->assertSessionHasErrors('email');

        $user->refresh();

        $this->assertSame($original, $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/settings/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/profile')
            ->delete('/settings/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->fresh());
    }
}

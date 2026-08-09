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

    // The address is the account's recovery channel: whoever controls it can request a
    // password reset and own the account outright. Changing it therefore re-proves the
    // password, exactly like minting a registry token or enrolling a second factor — the
    // gate below is what keeps a stolen session from being upgraded into permanent
    // ownership, and it is asserted here rather than assumed.
    //
    // The cases that legitimately change the address carry the confirmation stamp as a
    // precondition. That is not the gate going missing: the gate itself is pinned by
    // test_changing_the_email_address_requires_a_recent_password_confirmation below.

    public function test_changing_the_email_address_requires_a_recent_password_confirmation()
    {
        $user = User::factory()->create();
        $original = $user->email;

        $this->actingAs($user)
            ->patch('/settings/profile', [
                'name' => $user->name,
                'email' => 'attacker@evil.tld',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame($original, $user->refresh()->email);
    }

    public function test_a_stale_password_confirmation_does_not_open_the_email_change()
    {
        $user = User::factory()->create();
        $original = $user->email;

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time() - (int) config('auth.password_timeout') - 1])
            ->patch('/settings/profile', [
                'name' => $user->name,
                'email' => 'attacker@evil.tld',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame($original, $user->refresh()->email);
    }

    public function test_a_json_caller_is_told_to_confirm_rather_than_being_redirected()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/settings/profile', [
                'name' => $user->name,
                'email' => 'attacker@evil.tld',
            ])
            ->assertStatus(423);

        $this->assertSame($user->email, $user->refresh()->email);
    }

    public function test_a_non_string_email_cannot_slip_past_the_gate()
    {
        $user = User::factory()->create();

        // An address the comparison cannot read is treated as a change, not as "unchanged".
        $this->actingAs($user)
            ->patch('/settings/profile', [
                'name' => $user->name,
                'email' => ['attacker@evil.tld'],
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame($user->email, $user->refresh()->email);
    }

    public function test_a_name_only_change_does_not_demand_the_password()
    {
        $user = User::factory()->create();

        // The gate is on the recovery channel, not on the profile form. Asking for a
        // password to fix a typo in a display name would be friction with no attacker
        // behind it — and friction is what makes people turn gates off.
        $this->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Renamed',
                'email' => $user->email,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertSame('Renamed', $user->refresh()->name);
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
            ->withSession(['auth.password_confirmed_at' => time()])
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
            ->withSession(['auth.password_confirmed_at' => time()])
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
            ->withSession(['auth.password_confirmed_at' => time()])
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

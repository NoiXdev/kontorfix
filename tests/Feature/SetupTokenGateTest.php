<?php

use App\Enums\SetupGateState;
use App\Models\Organization;
use App\Models\User;
use App\Services\Setup\SetupGate;
use App\Services\Setup\SetupToken;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The first-run wizard creates the operator organization and the first super-admin
 * without any authentication. The setup token is the only thing standing between an
 * anonymous request and total instance takeover, so this file pins down what the gate
 * does in every state — including the states where the token store lets us down.
 *
 * @return array<string,mixed>
 */
function gatePayload(array $overrides = []): array
{
    return array_merge([
        'admin_name' => 'Mallory',
        'admin_email' => 'mallory@example.com',
        'admin_password' => 'correct-horse-battery-staple',
        'admin_password_confirmation' => 'correct-horse-battery-staple',
        'organization_name' => 'Squatted GmbH',
        'registry_name' => 'Pakete',
        'registry_slug' => 'pakete',
        'registry_public' => false,
        'mailer' => 'log',
        'storage_driver' => 'local',
    ], $overrides);
}

/**
 * Simulates the production off-state where the token store is unreachable: reading the
 * setup token raises instead of returning a value.
 */
function breakSetupTokenReads(): void
{
    $repository = Mockery::mock(Cache::store())->makePartial();
    $repository->shouldReceive('get')
        ->with('setup.token')
        ->andThrow(new RuntimeException('cache backend unavailable'));

    Cache::swap($repository);
}

// ---------------------------------------------------------------------------
// Fail-open reproducers
// ---------------------------------------------------------------------------

it('refuses an anonymous setup submission when no token was ever stored', function () {
    // Production off-states that all end here: redis lost the key, the entrypoint's
    // `setup:token` failed and was swallowed by `|| true`, an operator ran
    // `cache:clear`, or the cache store cannot outlive the process that wrote it.
    config()->set('kontorfix.setup.require_token', true);

    expect(app(SetupToken::class)->current())->toBeNull();

    $this->post('/setup', gatePayload())->assertForbidden();

    expect(User::query()->count())->toBe(0);
    expect(Organization::query()->count())->toBe(0);
});

it('refuses an anonymous setup submission when the token store cannot be read', function () {
    config()->set('kontorfix.setup.require_token', true);
    breakSetupTokenReads();

    $this->post('/setup', gatePayload())->assertForbidden();

    expect(User::query()->count())->toBe(0);
});

it('keeps the wizard locked when no token was ever stored', function () {
    config()->set('kontorfix.setup.require_token', true);

    $this->get('/setup')->assertOk()->assertInertia(fn ($page) => $page->where('locked', true));
});

it('keeps the wizard locked when the token store cannot be read', function () {
    config()->set('kontorfix.setup.require_token', true);
    breakSetupTokenReads();

    $this->get('/setup')->assertOk()->assertInertia(fn ($page) => $page->where('locked', true));
});

it('refuses the wizard mail test when no token was ever stored', function () {
    // Otherwise this is an anonymous arbitrary-host/arbitrary-port TCP connect with
    // the transport error echoed back — an open SMTP port scanner.
    config()->set('kontorfix.setup.require_token', true);

    $this->postJson('/setup/mail-test', ['mailer' => 'log', 'recipient' => 'x@example.com'])
        ->assertForbidden();
});

it('refuses the wizard mail test when the token store cannot be read', function () {
    config()->set('kontorfix.setup.require_token', true);
    breakSetupTokenReads();

    $this->postJson('/setup/mail-test', ['mailer' => 'log', 'recipient' => 'x@example.com'])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// The ordinary states — the fix must not lock out a legitimate first-run operator
// ---------------------------------------------------------------------------

it('lets the operator complete setup with a valid token', function () {
    config()->set('kontorfix.setup.require_token', true);
    $token = app(SetupToken::class)->regenerate();

    // Presented as a POST body, never as `?token=` — see SetupTokenTransportTest.
    $this->post('/setup/unlock', ['token' => $token])->assertRedirect(route('setup.show'));
    $this->get('/setup')->assertInertia(fn ($page) => $page->where('locked', false));
    $this->post('/setup', gatePayload())->assertRedirect(route('dashboard'));

    expect(User::query()->count())->toBe(1);
});

it('lets the operator send a wizard test mail with a valid token', function () {
    config()->set('kontorfix.setup.require_token', true);
    $token = app(SetupToken::class)->regenerate();

    $this->post('/setup/unlock', ['token' => $token]);
    $this->postJson('/setup/mail-test', ['mailer' => 'log', 'recipient' => 'admin@example.com'])
        ->assertOk()->assertJsonPath('ok', true);
});

it('refuses a wrong token', function () {
    config()->set('kontorfix.setup.require_token', true);
    app(SetupToken::class)->regenerate();

    $this->post('/setup/unlock', ['token' => 'not-the-token'])->assertSessionHasErrors('token');
    $this->get('/setup')->assertInertia(fn ($page) => $page->where('locked', true));
    $this->post('/setup', gatePayload())->assertForbidden();

    expect(User::query()->count())->toBe(0);
});

it('refuses setup once the instance is set up, even with a valid token', function () {
    config()->set('kontorfix.setup.require_token', true);
    $token = app(SetupToken::class)->regenerate();

    // Unlock first, then let somebody else finish the instance.
    $this->post('/setup/unlock', ['token' => $token]);
    $this->get('/setup')->assertInertia(fn ($page) => $page->where('locked', false));
    User::factory()->create();

    // "Already complete" must stay distinguishable from "token refused": the wizard
    // no longer exists as a destination, so it redirects rather than 403s.
    $this->get('/setup')->assertRedirect(route('home'));
    $this->post('/setup', gatePayload())->assertRedirect(route('home'));
    $this->post('/setup/mail-test', ['mailer' => 'log', 'recipient' => 'x@example.com'])
        ->assertRedirect(route('home'));

    expect(User::query()->count())->toBe(1);
});

it('leaves the wizard open where the token is not required', function () {
    // Local development / CI: no entrypoint ever ran, and demanding a token nobody
    // printed would make the wizard unreachable.
    config()->set('kontorfix.setup.require_token', false);

    $this->get('/setup')->assertOk()->assertInertia(fn ($page) => $page->where('locked', false));
    $this->post('/setup', gatePayload())->assertRedirect(route('dashboard'));

    expect(User::query()->count())->toBe(1);
});

it('requires the token by default outside local and testing', function () {
    // The config default is what actually protects a shipped deployment, so it is
    // asserted rather than assumed.
    config()->set('kontorfix.setup.require_token', null);

    $request = Request::create('/setup');
    $request->setLaravelSession(app('session.store'));

    app()->detectEnvironment(fn () => 'production');
    expect(app(SetupGate::class)->state($request))->toBe(SetupGateState::Locked);

    app()->detectEnvironment(fn () => 'local');
    expect(app(SetupGate::class)->state($request))->toBe(SetupGateState::Open);
});

it('refuses an anonymous setup submission in production with no token configured', function () {
    config()->set('kontorfix.setup.require_token', null);
    app()->detectEnvironment(fn () => 'production');

    // CSRF verification only self-disables while the app reports the testing
    // environment, and this test deliberately leaves it — 419 would mask the 403.
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/setup', gatePayload())->assertForbidden();

    expect(User::query()->count())->toBe(0);
});

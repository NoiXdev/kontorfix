<?php

use App\Http\Middleware\TrustHosts;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Services\Http\TrustedHosts;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * A05 — host-header injection into generated URLs. `Request::getTrustedHosts()` was
 * empty and nothing pinned the URL root, so `Host: attacker.example.net` on
 * POST /forgot-password produced a reset link on the attacker's domain inside the
 * *victim's* mailbox: click it and the single-use token is handed over.
 *
 * Two independent controls, tested separately, because they fail differently:
 *   - the URL root is pinned to APP_URL, so a generated link is correct even where a
 *     hostile host is somehow accepted;
 *   - untrusted hosts are refused outright, which also covers the consumers that build
 *     URLs from getSchemeAndHttpHost() rather than the URL generator.
 */
const ATTACKER = 'http://attacker.example.net';

function resetLinkFor(User $user): string
{
    $link = '';

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user, &$link) {
        $link = (string) $n->toMail($user)->actionUrl;

        return true;
    });

    return $link;
}

it('keeps the password reset link on the application host when the Host header lies', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(ATTACKER.'/forgot-password', ['email' => $user->email]);

    expect(resetLinkFor($user))
        ->toStartWith((string) config('app.url'))
        ->not->toContain('attacker.example.net');
});

it('keeps the email verification link on the application host when the Host header lies', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post(ATTACKER.'/email/verification-notification');

    $link = '';
    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $n) use ($user, &$link) {
        $link = (string) $n->toMail($user)->actionUrl;

        return true;
    });

    expect($link)->toStartWith((string) config('app.url'))
        ->and($link)->not->toContain('attacker.example.net');
});

it('roots the slug-mode registry metadata at the application url, not at the request host', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    PackageVersion::factory()->for($pkg)->create();
    $group->packages()->attach($pkg);

    $body = json_encode($this->withHeaders(tokenHeaderFor($group))
        ->getJson(ATTACKER.'/r/kadenz/p2/acme/demo.json')
        ->assertOk()
        ->json());

    expect($body)->not->toContain('attacker.example.net')
        ->and($body)->toContain(str_replace('/', '\/', (string) config('app.url').'/r/kadenz/dists/'));
});

it('trusts the application host, its subdomains and the loopback names', function () {
    $patterns = TrustedHosts::patterns();

    expect(hostIsTrusted('localhost', $patterns))->toBeTrue()
        ->and(hostIsTrusted('127.0.0.1', $patterns))->toBeTrue()
        ->and(hostIsTrusted((string) parse_url((string) config('app.url'), PHP_URL_HOST), $patterns))->toBeTrue()
        ->and(hostIsTrusted('attacker.example.net', $patterns))->toBeFalse();
});

it('trusts a hostname the operator attaches after the allowlist was already cached', function () {
    // Warm the cache first, deliberately: attaching a domain has to invalidate it, or
    // the new hostname is refused with a 400 until the entry expires.
    TrustedHosts::patterns();
    expect(Cache::has(TrustedHosts::CACHE_KEY))->toBeTrue();

    $group = Group::factory()->for(Organization::factory())->create();
    Domain::factory()->for($group)->create(['hostname' => 'packages.customer.example']);

    expect(hostIsTrusted('packages.customer.example', TrustedHosts::patterns()))->toBeTrue();
});

it('wires the allowlist into the framework middleware', function () {
    // The check above proves the patterns are right; this one proves they are installed.
    // Without it, deleting the trustHosts() call in bootstrap/app.php breaks nothing.
    // App\Http\Middleware\TrustHosts is the framework class's replacement (bootstrap/app.php
    // swaps it in so the health endpoint can be exempted from the allowlist) and shares the
    // same static `hosts()` configuration, since TrustHosts::at() writes onto the parent.
    expect(app(Kernel::class)->getGlobalMiddleware())->toContain(TrustHosts::class)
        ->and((new TrustHosts(app()))->hosts())->toBe(TrustedHosts::patterns());
});

it('keeps the allowlist in force when APP_URL was written without a scheme', function () {
    // `parse_url('registry.example.test', PHP_URL_HOST)` is null: the value parses as a
    // path, not as a host. Reading it raw made one typo switch off the allowlist AND the
    // URL root pinning at once — a single environment variable silently removing two
    // controls. The value is normalised before either control reads it instead.
    config(['app.url' => 'registry.example.test']);

    $patterns = TrustedHosts::patterns();

    expect($patterns)->not->toBe([])
        ->and(hostIsTrusted('registry.example.test', $patterns))->toBeTrue()
        ->and(hostIsTrusted('sub.registry.example.test', $patterns))->toBeTrue()
        ->and(hostIsTrusted('attacker.example.net', $patterns))->toBeFalse();
});

it('keeps the reset link pinned when APP_URL was written without a scheme', function () {
    config(['app.url' => 'registry.example.test']);
    Notification::fake();
    $user = User::factory()->create();

    // Reachability anchor: the POST reaches the reset handler and a notification is
    // actually sent, so the link below is one this request generated — the assertion is
    // about URL generation, not about a refusal at the router or the throttle.
    $this->post(ATTACKER.'/forgot-password', ['email' => $user->email])
        ->assertSessionHasNoErrors();
    Notification::assertSentTo($user, ResetPassword::class);

    // The host is the asserted property. The scheme still follows the request, as it did
    // before — `forceRootUrl` never pinned it, and only a trusted proxy can set it.
    expect(parse_url(resetLinkFor($user), PHP_URL_HOST))->toBe('registry.example.test');
});

it('drops the allowlist rather than locking the instance out when APP_URL names no host', function () {
    // An empty allowlist means "trust everything" in Symfony. That is the right
    // direction for a value the operator may simply not have set: the URL root pinning
    // above is the control that does not depend on configuration being present.
    config(['app.url' => '']);

    expect(TrustedHosts::patterns())->toBe([]);
});

it('roots a generated link at the request host when that host is an attached registry hostname', function () {
    // The availability half of the same control: pinning every generated URL to APP_URL
    // fixed the reset-link injection and broke multi-hostname operation, because every
    // asset, Inertia XHR and form action on a tenant hostname became cross-origin.
    User::factory()->create();
    $group = Group::factory()->for(Organization::factory())->create();
    Domain::factory()->for($group)->create(['hostname' => 'packages.customer.example']);

    // Reachability anchor: an unauthenticated GET /dashboard is answered by the `auth`
    // middleware's own redirect, so the asserted Location is a route() URL generated
    // inside this request — not a router 404, a setup bounce or a refused host.
    $this->get('http://packages.customer.example/dashboard')
        ->assertRedirect('http://packages.customer.example/login');
});

it('refuses to root a generated link at a Host that is not an attached registry hostname', function () {
    User::factory()->create();

    $this->get(ATTACKER.'/dashboard')
        ->assertRedirect(rtrim((string) config('app.url'), '/').'/login');
});

it('refuses a request whose Host is not on the allowlist', function () {
    // TrustHosts stands down under `runningUnitTests()`, so install the very patterns it
    // would install and drive a real request through the framework's host validation.
    // Uses /login rather than /up: the health endpoint is deliberately exempted from this
    // check now (see tests/Feature/Http/HealthEndpointHostTest.php), so it can no longer
    // stand in for "an unlisted host is refused" here.
    Request::setTrustedHosts(TrustedHosts::patterns());

    try {
        $this->get(ATTACKER.'/login')->assertStatus(400);
        // No user exists on a fresh test database, so RequireSetup bounces /login to the
        // wizard — the point here is that the trusted host reaches routing at all,
        // not what it renders once there.
        $this->get('http://localhost/login')->assertRedirect(route('setup.show'));
    } finally {
        Request::setTrustedHosts([]);
    }
});

function hostIsTrusted(string $host, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (preg_match('{'.$pattern.'}i', $host) === 1) {
            return true;
        }
    }

    return false;
}

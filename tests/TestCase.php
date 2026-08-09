<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\KnownClients;
use App\Services\Upstream\HostResolver;
use App\Services\Upstream\SystemHostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FixtureHostResolver;

abstract class TestCase extends BaseTestCase
{
    /**
     * The outbound address policy fails closed on a host it cannot resolve, so leaving
     * the suite on the machine's real DNS would make every fixture host (`repo.test`,
     * `hooks.example`, …) unroutable and the whole outbound suite red for reasons that
     * have nothing to do with the code under test.
     *
     * The substitution is a container binding, not a static setter on UrlSafety: the
     * resolver is the single decision point of the address policy and should not have a
     * public mutator that production code can reach. Container bindings are torn down
     * with the application between tests, so there is nothing to restore either.
     *
     * See FixtureHostResolver for what the fixture namespace resolves to — in
     * particular, `*.internal` and single-label names now resolve to a *private*
     * address, which is what makes the allowlist and SSRF tests load-bearing.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(HostResolver::class, new FixtureHostResolver);
    }

    /**
     * Pins one hostname to an exact set of addresses for the duration of a test.
     *
     * @param  list<string>  $ips
     */
    protected function resolveHostTo(string $host, array $ips): void
    {
        $resolver = $this->app->make(HostResolver::class);

        if ($resolver instanceof FixtureHostResolver) {
            $resolver->map($host, $ips);
        }
    }

    /**
     * Hands the test back to the real system resolver, for the cases whose whole point
     * is what the C library and the configured nameservers actually answer.
     */
    protected function useRealHostResolver(): void
    {
        $this->app->instance(HostResolver::class, new SystemHostResolver);
    }

    /**
     * Presents the request as coming from a browser this account has signed in from
     * before — the marker App\Services\Auth\KnownClients issues on every completed
     * authentication.
     *
     * Built through the real service and handed to the framework's own cookie plumbing
     * (withCookie encrypts it exactly as EncryptCookies expects), so a test that relies
     * on recognition breaks if the production encoding moves.
     */
    protected function withKnownClient(User ...$users): static
    {
        $clients = $this->app->make(KnownClients::class);

        $fingerprints = array_map(
            fn (User $user): string => $clients->fingerprint((string) $user->email),
            $users,
        );

        return $this->withCookie(KnownClients::COOKIE, (string) json_encode($fingerprints));
    }

    /**
     * Puts the instance past its first-run setup.
     *
     * An instance without a single user account serves nothing but the setup wizard
     * (see App\Http\Middleware\RequireSetup), so any test that exercises the normal
     * post-installation web UI — login, registration, OIDC, the landing page — has to
     * declare that an account already exists. Tests that create their own user get
     * this implicitly and do not need the call.
     */
    protected function instanceAlreadySetUp(): User
    {
        return User::factory()->create();
    }
}

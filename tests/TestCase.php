<?php

namespace Tests;

use App\Models\User;
use App\Services\Upstream\UrlSafety;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The outbound address policy fails closed on a host it cannot resolve, so leaving it
     * on the machine's real DNS would make every fixture host (`repo.test`,
     * `hooks.example`, …) unroutable and the whole outbound suite red for a reason that
     * has nothing to do with the code under test. Resolve the fixture space to one public
     * address instead — deterministic, offline, and the same allow/deny decision a real
     * public host would get. A test that needs a different answer (an internal address, or
     * no address at all) calls UrlSafety::resolveHostsUsing() itself; this reinstalls the
     * default before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        UrlSafety::resolveHostsUsing(function (string $host): array {
            // Mirror the one property of real DNS this suite depends on: a name whose
            // last label is not alphabetic is not a name, so it never resolves. That
            // keeps the numeric host encodings (0x7f000001, 127.1) unresolvable here
            // exactly as they are in the container.
            $labels = explode('.', $host);
            $tld = (string) end($labels);

            return ctype_alpha($tld) ? ['93.184.216.34'] : [];
        });
    }

    protected function tearDown(): void
    {
        UrlSafety::resolveHostsUsing(null);

        parent::tearDown();
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

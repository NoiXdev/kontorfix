<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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

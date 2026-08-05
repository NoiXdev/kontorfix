<?php

namespace App\Services\Setup;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Decides whether the instance still needs its first-run setup.
 *
 * "Needs setup" is defined purely as "there is no user account yet". That keeps the
 * check cheap and, more importantly, makes it impossible to re-open the wizard once
 * anybody exists — the wizard creates an admin without authenticating, so it must be
 * unreachable the moment the instance has any user at all.
 */
class SetupStatus
{
    public function needsSetup(): bool
    {
        // Before the first `migrate` the users table does not exist yet. Reporting
        // "no setup needed" there keeps artisan usable on a virgin database instead
        // of failing every command with a QueryException.
        try {
            if (! Schema::hasTable('users')) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return User::query()->doesntExist();
    }

    public function isComplete(): bool
    {
        return ! $this->needsSetup();
    }
}

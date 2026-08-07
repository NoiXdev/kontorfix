<?php

namespace App\Services;

use App\Models\RegistryToken;
use App\Models\User;

/**
 * Keeps personal registry tokens in step with the account behind them.
 *
 * RegistryToken::findByPlainText() already refuses a token whose owner has lost the
 * access it was issued under, so authorization does not depend on this service — it is
 * the housekeeping half: after a deprovisioning action the dead rows are removed, so the
 * admin token list stays a truthful inventory instead of accumulating credentials that
 * look live but are not.
 */
class RegistryTokenLifecycleService
{
    /**
     * Revoke every personal token of the user their owner is no longer entitled to.
     * Call after any change to membership, home organization or role.
     *
     * @return int number of revoked tokens
     */
    public function revokeUnentitled(User $user): int
    {
        // The membership relations decide entitlement — re-read them rather than trust
        // whatever was loaded before the detach/update that triggered this call.
        $user->unsetRelation('organizations');
        $user->unsetRelation('organization');

        $stale = $user->registryTokens()->get()
            ->each(fn (RegistryToken $token) => $token->setRelation('user', $user))
            ->reject(fn (RegistryToken $token) => $token->ownerIsStillEntitled());

        if ($stale->isEmpty()) {
            return 0;
        }

        return RegistryToken::whereKey($stale->modelKeys())->delete();
    }
}

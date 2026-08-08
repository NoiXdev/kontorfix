<?php

namespace App\Services;

use App\Models\RegistryToken;
use App\Models\User;

/**
 * Keeps personal registry tokens in step with the account behind them.
 *
 * RegistryToken::findByPlainText() already refuses a token whose owner has lost the
 * access it was issued under, so authorization does not depend on this service — it is
 * the housekeeping half: after a deprovisioning action the dead credentials are marked,
 * so the admin token list stays a truthful inventory instead of accumulating credentials
 * that look live but are not.
 *
 * Two deliberate limits, because this runs on operator input:
 *
 *  - it marks `revoked_at`, it does not delete. Nothing an operator can trigger from a
 *    console should be unrecoverable, and a revoked row still says who held what.
 *  - it is called from the *membership* paths (detaching an organization, deleting an
 *    account) and not from Admin/UserController::update. A role or home-org change is a
 *    dropdown in the same form as the name and the email; it is the one lifecycle event
 *    that routinely happens to a current employee and is routinely a mistake. Entitlement
 *    for those is enforced where it belongs — at resolution time, derived, and therefore
 *    reversible the moment the mistake is undone.
 */
class RegistryTokenLifecycleService
{
    /**
     * Revoke every personal token of the user their owner is no longer entitled to.
     * Call after a membership has been removed.
     *
     * @return int number of revoked tokens
     */
    public function revokeUnentitled(User $user): int
    {
        // The membership relations decide entitlement — re-read them rather than trust
        // whatever was loaded before the detach/update that triggered this call.
        $user->unsetRelation('organizations');
        $user->unsetRelation('organization');

        $stale = $user->registryTokens()->notRevoked()->get()
            ->each(fn (RegistryToken $token) => $token->setRelation('user', $user))
            ->reject(fn (RegistryToken $token) => $token->ownerIsStillEntitled());

        if ($stale->isEmpty()) {
            return 0;
        }

        return RegistryToken::whereKey($stale->modelKeys())->update(['revoked_at' => now()]);
    }
}

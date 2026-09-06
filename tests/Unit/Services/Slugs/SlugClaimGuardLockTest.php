<?php

use App\Services\Slugs\SlugClaimGuard;

/**
 * Placed in Unit rather than Feature deliberately: Feature tests carry RefreshDatabase,
 * which wraps every test in its own outer transaction — so DB::transactionLevel() can never
 * observe 0 there, and this precondition could never be exercised. A Unit test boots the
 * app without that wrapper, so the connection genuinely starts with no open transaction.
 */
it('refuses to take the lock outside an open transaction', function () {
    expect(fn () => app(SlugClaimGuard::class)->lock('whatever'))->toThrow(LogicException::class);
});

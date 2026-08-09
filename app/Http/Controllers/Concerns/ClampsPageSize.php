<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * The page size for the `/api/v1` collection endpoints.
 *
 * `min((int) $request->query('per_page', 25), 100)` bounded only the top of the range.
 * Underneath it, `per_page=0` reached the paginator as a zero page size and was an
 * unhandled 500, and `per_page=-1` — like every non-numeric value, which casts to 0 —
 * made Laravel drop the `LIMIT` and return the entire table in one response. On a
 * collection endpoint that is a denial-of-service primitive available to any key holder,
 * not just a wrong status code.
 *
 * Out-of-range values are clamped rather than rejected with 422, for three reasons:
 * the upper bound already clamped silently, so refusing the lower half of the same
 * parameter would be incoherent; no client can be relying on the previous behaviour of
 * either value, since one was a 500 and the other is the abuse this removes; and a 422
 * would introduce a new client-visible error class across five endpoints for a
 * parameter that has always been best-effort. The bound the caller receives is reported
 * back in `meta.per_page`, so a client bug is visible in the response rather than hidden.
 */
trait ClampsPageSize
{
    private const MAX_PAGE_SIZE = 100;

    protected function perPage(Request $request): int
    {
        return max(1, min((int) $request->query('per_page', 25), self::MAX_PAGE_SIZE));
    }
}

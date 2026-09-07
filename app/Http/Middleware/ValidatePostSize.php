<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\ValidatePostSize as Base;

/**
 * Exempts `/v2/*` (the OCI distribution API) from the framework's own post-size guard.
 *
 * The parent class is part of Laravel's default GLOBAL middleware stack — every request,
 * every method, unconditionally — and compares the raw `Content-Length` header against
 * `post_max_size`, throwing `PostTooLargeException` when it is exceeded. That check has
 * nothing to do with what it sounds like it protects: post_max_size bounds PHP's own
 * `$_POST`/`$_FILES` auto-population (see docker/php.ini's own comment), which
 * BlobController never uses — every OCI blob is read via
 * `$request->getContent(asResource: true)`, a raw stream `post_max_size` does not gate at
 * all. A single image layer legitimately exceeds post_max_size by design (spec §4's own
 * 500 MiB+ requirement), and Docker's chunked PATCH sends each chunk with a real, large
 * `Content-Length` — not HTTP chunked Transfer-Encoding — so this check fires on perfectly
 * ordinary OCI traffic the moment post_max_size is a real, finite number.
 *
 * THIS IS NOT A COSMETIC FIX. While post_max_size was 0 ("no limit" — the Critical DoS
 * this branch's php.ini fix closes), `$max > 0` in the parent's check was false and this
 * guard was silently a no-op for every route, OCI included — which is exactly why the
 * gap was never noticed. The moment post_max_size became a real number, this guard woke
 * up and started throwing PostTooLargeException for any blob chunk/monolithic push above
 * it: reproduced directly against the real e2e stack, `docker push` failing outright on
 * a >500 MiB layer with `500 Internal Server Error` on the finishing PUT. Worse than a
 * clean rejection: with APP_DEBUG on, Laravel's own debug exception page renders the
 * `Request` object via `(string) $request`, which calls `Request::getContent()` a SECOND
 * time — this one WITHOUT `asResource`, reading the entire (100+ MiB) body into a single
 * PHP string — and that second read is what actually exhausts memory_limit and crashes
 * the worker. A vendor-level latent bug, not something to patch in vendor/, but the
 * reachable trigger for it under `/v2/*` is entirely closed by exempting the route here:
 * PostTooLargeException never fires for it, so that renderer path is never reached.
 *
 * Every OTHER route keeps the parent's real protection unchanged.
 *
 * `$request` is intentionally untyped, matching the parent's own signature exactly — a
 * narrower type here (`Illuminate\Http\Request`) is an incompatible override and PHP
 * raises a fatal "must be compatible" error at class-load time rather than a lint
 * warning, the same trap App\Http\Middleware\TrustHosts's own note describes for its
 * `$next` parameter (the parent classes simply disagree on which of the two they type).
 */
class ValidatePostSize extends Base
{
    public function handle($request, Closure $next)
    {
        $path = $request->getPathInfo();

        if ($path === '/v2' || str_starts_with($path, '/v2/')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}

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
        // A REAL SEGMENT after `/v2/` is required, and that is the whole condition — the
        // exemption used to also cover the bare `/v2` and, through it, a route that has
        // nothing to do with OCI.
        //
        // `/v2` and `/v2/` are the version endpoint, which is GET-only and carries no body,
        // so exempting them protects nothing. What they DO reach on another method is npm's
        // bare publish route: `PUT /{package}` (routes/registry.php) constrains {package} to
        // `[a-z0-9._-]+`, which "v2" satisfies, and no OCI route answers PUT at that path.
        // So `PUT /v2` — matched by path here, exempted, and then routed to
        // NpmController::publish() — arrived at a controller that reads the entire body into
        // a string, with the framework's post-size guard deliberately switched off for it.
        // The one route the exemption exists to protect could not be reached that way at
        // all; the one it accidentally opened had no defence of its own.
        //
        // Every body-carrying OCI endpoint is `/v2/{name}/…`, so none of them lose the
        // exemption: `blobs/uploads/`, its PATCH/PUT continuations, and `manifests/{ref}`
        // all have a segment after the prefix. A large body PUT to some other `/v2/…` path
        // stays exempt and is harmless — no route matches it, so nothing ever reads it.
        if (preg_match('#^/v2/.+#', $request->getPathInfo()) === 1) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}

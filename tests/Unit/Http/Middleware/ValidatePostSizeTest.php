<?php

use App\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

/**
 * The exemption this subclass adds, and the fact that it is scoped to `/v2/*` alone,
 * verified directly against the middleware rather than through a real oversized HTTP
 * body: reproducing the actual crash needs a real FrankenPHP container with a finite
 * post_max_size and APP_DEBUG on (see the DoS-fix commit and bootstrap/app.php's own
 * comment) — not something this suite can exercise portably. What IS portable, and what
 * actually matters for this file, is the routing decision: does `/v2/*` skip the parent's
 * check regardless of Content-Length, and does every other route still get it. A
 * Content-Length far above any real php.ini's post_max_size (this suite's own ddev
 * environment runs 100M) is used so the "still guards" case is unambiguous regardless of
 * where it runs.
 */
it('exempts /v2/ from the post-size guard even with an oversized Content-Length', function () {
    $middleware = new ValidatePostSize;
    $request = Request::create('/v2/app/blobs/uploads/', 'POST', server: [
        'CONTENT_LENGTH' => (string) (500 * 1024 * 1024),
    ]);

    $response = $middleware->handle($request, fn ($req) => response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('exempts the bare /v2 version-check path too', function () {
    $middleware = new ValidatePostSize;
    $request = Request::create('/v2', 'GET', server: [
        'CONTENT_LENGTH' => (string) (500 * 1024 * 1024),
    ]);

    $response = $middleware->handle($request, fn ($req) => response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('still guards every other route with the same oversized Content-Length', function () {
    $middleware = new ValidatePostSize;
    $request = Request::create('/login', 'POST', server: [
        'CONTENT_LENGTH' => (string) (500 * 1024 * 1024),
    ]);

    expect(fn () => $middleware->handle($request, fn ($req) => response('ok')))
        ->toThrow(PostTooLargeException::class);
});

it('does not exempt a route that merely starts with "v2" as a segment prefix, not a real boundary', function () {
    // `str_starts_with($path, '/v2/')` requires the trailing slash — `/v2foo/...` must
    // not be swallowed by the exemption meant for the OCI prefix alone.
    $middleware = new ValidatePostSize;
    $request = Request::create('/v2foo/bar', 'POST', server: [
        'CONTENT_LENGTH' => (string) (500 * 1024 * 1024),
    ]);

    expect(fn () => $middleware->handle($request, fn ($req) => response('ok')))
        ->toThrow(PostTooLargeException::class);
});

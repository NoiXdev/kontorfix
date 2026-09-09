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

it('does NOT exempt the bare /v2, which is npm\'s publish route on any method but GET', function () {
    // This case asserted the opposite until the hole it describes was found. The bare
    // `/v2` is the OCI version check on GET — a request with no body, which needs no
    // exemption from a body-size guard. On PUT it is something else entirely: npm's bare
    // publish route (routes/registry.php) constrains {package} to `[a-z0-9._-]+`, which
    // "v2" satisfies, and no OCI route answers PUT there. So exempting the bare path
    // handed an unguarded body straight to NpmController::publish(), which reads the whole
    // request into a string — the one route the exemption exists to protect was not even
    // reachable that way.
    $middleware = new ValidatePostSize;
    $request = Request::create('/v2', 'PUT', server: [
        'CONTENT_LENGTH' => (string) (500 * 1024 * 1024),
    ]);

    expect(fn () => $middleware->handle($request, fn ($req) => response('ok')))
        ->toThrow(PostTooLargeException::class);
});

it('does NOT exempt /v2/ either, so the trailing slash is not a second way in', function () {
    // Laravel normalises the trailing slash when matching, so `PUT /v2/` reaches exactly
    // the same npm route the case above describes. An exemption written as
    // `str_starts_with($path, '/v2/')` would let it through while refusing `/v2` — the fix
    // for one spelling and not the other.
    $middleware = new ValidatePostSize;
    $request = Request::create('/v2/', 'PUT', server: [
        'CONTENT_LENGTH' => (string) (500 * 1024 * 1024),
    ]);

    expect(fn () => $middleware->handle($request, fn ($req) => response('ok')))
        ->toThrow(PostTooLargeException::class);
});

it('still exempts every real OCI endpoint, which all carry a segment after the prefix', function () {
    $middleware = new ValidatePostSize;

    $paths = [
        '/v2/app/blobs/uploads/',
        '/v2/app/blobs/uploads/0199a1f2-0000-7000-8000-000000000000',
        '/v2/app/manifests/1.0',
    ];

    foreach ($paths as $path) {
        $request = Request::create($path, 'PUT', server: [
            'CONTENT_LENGTH' => (string) (500 * 1024 * 1024),
        ]);

        expect($middleware->handle($request, fn ($req) => response('ok'))->getContent())
            ->toBe('ok', $path);
    }
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
    // The pattern anchors on `/v2/` with the slash, so `/v2foo/...` must not be swallowed
    // by the exemption meant for the OCI prefix alone.
    $middleware = new ValidatePostSize;
    $request = Request::create('/v2foo/bar', 'POST', server: [
        'CONTENT_LENGTH' => (string) (500 * 1024 * 1024),
    ]);

    expect(fn () => $middleware->handle($request, fn ($req) => response('ok')))
        ->toThrow(PostTooLargeException::class);
});

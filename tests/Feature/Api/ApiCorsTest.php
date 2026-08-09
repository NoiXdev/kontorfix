<?php

/**
 * With no `config/cors.php` the framework default applied: `allowed_origins => ['*']` on
 * the whole `/api/*` surface, so any page a user visited could read the unauthenticated
 * responses — including the 401-vs-404 existence differences — and an instance on an
 * internal network was readable from a browser inside it.
 */
it('does not answer an arbitrary origin with a wildcard CORS grant on /api', function () {
    // Reachability anchor: the instance's own origin IS answered with an explicit grant,
    // which proves HandleCors runs on this path. The refusal below is therefore the
    // allowlist deciding, not a middleware that is absent.
    $allowed = rtrim((string) config('app.url'), '/');

    $this->call('OPTIONS', '/api/v1/packages', [], [], [], [
        'HTTP_ORIGIN' => $allowed,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ])->assertHeader('Access-Control-Allow-Origin', $allowed);

    $preflight = $this->call('OPTIONS', '/api/v1/packages', [], [], [], [
        'HTTP_ORIGIN' => 'https://evil.example.net',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    // The grant is a static allowlist declaration, so the header may still be present —
    // what must never happen is that it is a wildcard or that it echoes the caller's
    // origin, either of which lets the caller's page read the response.
    expect($preflight->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('*')
        ->not->toBe('https://evil.example.net');

    // And the same for an actual request, not only the preflight.
    $actual = $this->getJson('/api/v1/packages', ['Origin' => 'https://evil.example.net']);

    expect($actual->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('*')
        ->not->toBe('https://evil.example.net');
});

it('keeps credentialed cross-origin reads off', function () {
    expect(config('cors.supports_credentials'))->toBeFalse()
        ->and(config('cors.allowed_origins'))->not->toContain('*');
});

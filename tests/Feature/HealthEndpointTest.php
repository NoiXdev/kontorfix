<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Laravel's built-in `health: '/up'` route renders a vendor Blade that pulls an
// unpinned @tailwindcss/browser build from jsDelivr and a stylesheet from
// fonts.bunny.net — third-party script on our own origin, no SRI, no pin. The
// container healthcheck only ever needs a status code.
it('answers the health check without loading any third-party asset', function () {
    $res = $this->get('/up');

    $res->assertOk();
    expect($res->getContent())
        ->not->toContain('cdn.jsdelivr.net')
        ->not->toContain('fonts.bunny.net')
        ->not->toContain('<script');
});

it('answers the health check as json', function () {
    $this->get('/up')->assertOk()->assertJsonPath('status', 'ok');
});

it('sets the security headers on the health check', function () {
    $this->get('/up')->assertHeader('X-Content-Type-Options', 'nosniff');
});

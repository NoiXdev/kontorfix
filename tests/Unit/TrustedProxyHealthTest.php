<?php

// TRUSTED_PROXIES ships trusting every RFC1918 range plus loopback, and both .env.example
// files pre-fill that value. docs/development.md tells operators to pin it to the concrete
// proxy address, but nothing enforced or even surfaced that — so the documented instruction
// was advice an operator could silently never follow.
//
// What the breadth costs: every IP-keyed limiter (throttle:api, the per-address login
// counter) becomes spoofable by anything that can reach the app from inside the private
// network — a compromised co-tenant container on a shared proxy network — and the IP written
// into the audit log becomes forgeable from that position. Not an internet attacker:
// Symfony's trusted-proxy walk stops at the first untrusted address, which behind Traefik is
// the real client.

use App\Services\Health\HealthService;

/** @return array{key:string,label:string,ok:bool,detail:string} */
function trustedProxyCheck(): array
{
    $check = collect(app(HealthService::class)->checks())->firstWhere('key', 'trusted-proxies');

    expect($check)->not->toBeNull();

    return $check;
}

it('warns when the proxy set is a broad private range', function () {
    config()->set('kontorfix.trusted_proxies', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1');

    $check = trustedProxyCheck();

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('10.0.0.0/8');
});

it('warns loudest about the wildcard', function () {
    config()->set('kontorfix.trusted_proxies', '*');

    expect(trustedProxyCheck()['ok'])->toBeFalse();
});

it('is satisfied by concrete proxy addresses', function () {
    config()->set('kontorfix.trusted_proxies', '172.18.0.5,172.18.0.6');

    $check = trustedProxyCheck();

    expect($check['ok'])->toBeTrue()
        ->and($check['detail'])->toContain('172.18.0.5');
});

it('accepts a tight subnet, which is how a proxy pool is legitimately expressed', function () {
    // /29 is eight addresses — a real proxy pool. The check is about breadth, not about
    // forbidding CIDR, so it must not force an operator to enumerate hosts.
    config()->set('kontorfix.trusted_proxies', '172.18.0.0/29');

    expect(trustedProxyCheck()['ok'])->toBeTrue();
});

it('warns when nothing is configured at all', function () {
    config()->set('kontorfix.trusted_proxies', '');

    expect(trustedProxyCheck()['ok'])->toBeFalse();
});

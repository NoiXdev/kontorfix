<?php

use App\Services\Http\TrustedHosts;
use Illuminate\Http\Request;

/**
 * v0.7.0 took the instance down here. Traefik's load-balancer healthcheck requests the
 * backend by container IP, so the Host header is "172.18.0.6:8080" — not on the allowlist.
 * The application answered 400, Traefik marked its only server down, and every request got
 * 503. The container's own healthcheck uses 127.0.0.1, which IS allowlisted, so Docker
 * reported the container healthy the whole time.
 *
 * TrustHosts stands down under runningUnitTests(), so these tests install the patterns by
 * hand — the same trick tests/Feature/Http/HostHeaderTrustTest.php already uses.
 *
 * The Host is driven via an absolute-URL request target (`$this->get('http://host/path')`),
 * not via a `Host` header array. Symfony's `Request::create()` docs say the URI always wins
 * over the passed $server vars: `prepareUrlForRequest()` rewrites a relative URI through
 * `url()` first, which stamps HTTP_HOST back to the app host and silently discards a `Host`
 * header override. An absolute-URL request target is the only way this test client actually
 * changes the host the framework validates against — see the identical pattern already used
 * by HostHeaderTrustTest.php's `ATTACKER.'/up'`.
 */
beforeEach(function () {
    Request::setTrustedHosts(TrustedHosts::patterns());
});

afterEach(function () {
    Request::setTrustedHosts([]);
});

it('answers the health check under a host that is not on the allowlist', function () {
    $this->get('http://172.18.0.6:8080/up')
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});

it('answers the health check under a bare container name', function () {
    $this->get('http://kontorfix-app:8080/up')->assertOk();
});

it('still answers the health check under the application host', function () {
    $this->get(rtrim((string) config('app.url'), '/').'/up')->assertOk();
});

it('still refuses an unlisted host on a route that is not the health check', function () {
    $this->get('http://172.18.0.6:8080/login')->assertStatus(400);
});

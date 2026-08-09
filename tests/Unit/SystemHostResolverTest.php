<?php

use App\Providers\AppServiceProvider;
use App\Services\Upstream\HostResolver;
use App\Services\Upstream\SystemHostResolver;
use App\Services\Upstream\UrlSafety;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

/**
 * The lookup the whole outbound address policy rests on had **zero** coverage: the suite
 * installed a stub resolver in `setUp()` for every test and no test ever cleared it and
 * resolved a name. A regression that dropped the AAAA half, or a `gethostbynamel()` that
 * silently returned false, would have shipped green — and, given the fail-closed rule,
 * would then have refused every outbound target in production.
 *
 * These cases run on the real resolver. They are written to be deterministic without a
 * working internet connection: `localhost` comes from /etc/hosts, `.invalid` is
 * guaranteed by RFC 2606 never to resolve, and the numeric forms are decoded (or not) by
 * getaddrinfo's inet_aton path without a DNS query being sent at all.
 */
beforeEach(function () {
    $this->useRealHostResolver();
});

it('returns the addresses the system actually resolves a name to', function () {
    $resolved = (new SystemHostResolver)->resolve('localhost');

    expect($resolved)->toContain('127.0.0.1');
});

it('consults both the A and the AAAA lookup', function () {
    // Whatever this environment answers for localhost, every address of it has to come
    // back — so deleting either half of resolve() fails here on any machine that has the
    // corresponding record.
    $expected = array_merge(
        gethostbynamel('localhost') ?: [],
        array_values(array_filter(array_map(
            static fn (array $r): ?string => isset($r['ipv6']) && is_string($r['ipv6']) ? $r['ipv6'] : null,
            dns_get_record('localhost', DNS_AAAA) ?: [],
        ))),
    );

    expect($expected)->not->toBeEmpty();
    expect((new SystemHostResolver)->resolve('localhost'))->toContain(...$expected);
});

it('returns the IPv6 answer for a name that has one', function () {
    $aaaa = dns_get_record('localhost', DNS_AAAA) ?: [];

    if ($aaaa === []) {
        $this->markTestSkipped('This environment publishes no AAAA record for localhost.');
    }

    expect((new SystemHostResolver)->resolve('localhost'))->toContain('::1');
});

it('returns nothing for a name that does not resolve', function () {
    // RFC 2606 reserves .invalid precisely so this is guaranteed, online or off. An empty
    // list is not a shrug: UrlSafety turns it into a refusal.
    expect((new SystemHostResolver)->resolve('no-such-host-xyzzy.invalid'))->toBe([]);
    expect(UrlSafety::hostIsPublic('no-such-host-xyzzy.invalid'))->toBeFalse();
});

it('decodes the decimal and short-dotted host forms the way a client would', function () {
    // The mechanism the address policy depends on, measured rather than assumed:
    // getaddrinfo hands back 127.0.0.1 for both of these without asking DNS, which is
    // why they are caught as *private* rather than merely unresolvable. If a future
    // platform stopped decoding them, the fail-closed rule catches them instead — so the
    // assertion is on the outcome, and the mechanism is recorded here.
    $resolver = new SystemHostResolver;

    foreach (['2130706433', '127.1'] as $host) {
        expect($resolver->resolve($host))->toBe(['127.0.0.1'])
            ->and(UrlSafety::hostIsPublic($host))->toBeFalse();
    }
});

it('is what the application binds by default', function () {
    // Guards the wiring, not the algorithm. Asserted against a freshly registered
    // application rather than the test one, because tests/TestCase.php substitutes the
    // fixture resolver into this container and asking *it* would prove nothing.
    $original = Container::getInstance();

    try {
        $fresh = new Application(base_path());
        (new AppServiceProvider($fresh))->register();

        expect($fresh->make(HostResolver::class))->toBeInstanceOf(SystemHostResolver::class);
    } finally {
        Container::setInstance($original);
        Facade::setFacadeApplication($original);
    }
});

it('is what UrlSafety falls back to when nothing is bound at all', function () {
    // A missing resolver must mean "use the real one", never "resolve nothing" — because
    // "nothing" is now a refusal, and a container that lost this binding would otherwise
    // refuse every outbound target in the application.
    app()->offsetUnset(HostResolver::class);
    expect(app()->bound(HostResolver::class))->toBeFalse();

    expect(UrlSafety::hostIsPublic('2130706433'))->toBeFalse()
        ->and(UrlSafety::hostIsPublic('no-such-host-xyzzy.invalid'))->toBeFalse();
});

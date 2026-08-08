<?php

use App\Services\Upstream\UrlSafety;

it('classifies ip addresses as public or private', function () {
    expect(UrlSafety::ipIsPublic('8.8.8.8'))->toBeTrue();
    expect(UrlSafety::ipIsPublic('93.184.216.34'))->toBeTrue();

    expect(UrlSafety::ipIsPublic('10.0.0.1'))->toBeFalse();
    expect(UrlSafety::ipIsPublic('192.168.1.1'))->toBeFalse();
    expect(UrlSafety::ipIsPublic('127.0.0.1'))->toBeFalse();
    expect(UrlSafety::ipIsPublic('169.254.169.254'))->toBeFalse(); // Cloud-Metadata
    expect(UrlSafety::ipIsPublic('::1'))->toBeFalse();
    expect(UrlSafety::ipIsPublic('fd00::1'))->toBeFalse();
});

it('rejects the IPv4 ranges filter_var leaves open', function () {
    // 100.64.0.0/10 — CGNAT / shared address space (Tailscale, several managed-k8s CNIs).
    expect(UrlSafety::ipIsPublic('100.64.0.0'))->toBeFalse();
    expect(UrlSafety::ipIsPublic('100.100.100.100'))->toBeFalse();
    expect(UrlSafety::ipIsPublic('100.127.255.255'))->toBeFalse();

    // 198.18.0.0/15 — benchmarking.
    expect(UrlSafety::ipIsPublic('198.18.0.0'))->toBeFalse();
    expect(UrlSafety::ipIsPublic('198.19.255.255'))->toBeFalse();

    // 224.0.0.0/4 — multicast.
    expect(UrlSafety::ipIsPublic('224.0.0.1'))->toBeFalse();
    expect(UrlSafety::ipIsPublic('239.255.255.255'))->toBeFalse();

    // 192.0.0.0/24 — IETF protocol assignments.
    expect(UrlSafety::ipIsPublic('192.0.0.0'))->toBeFalse();
    expect(UrlSafety::ipIsPublic('192.0.0.255'))->toBeFalse();
});

it('keeps the addresses just outside those ranges public', function () {
    expect(UrlSafety::ipIsPublic('100.63.255.255'))->toBeTrue();
    expect(UrlSafety::ipIsPublic('100.128.0.1'))->toBeTrue();
    expect(UrlSafety::ipIsPublic('198.17.255.255'))->toBeTrue();
    expect(UrlSafety::ipIsPublic('198.20.0.1'))->toBeTrue();
    expect(UrlSafety::ipIsPublic('223.255.255.255'))->toBeTrue();
    expect(UrlSafety::ipIsPublic('192.0.1.1'))->toBeTrue();
});

it('rejects the added ranges through an IPv4-mapped IPv6 literal too', function () {
    expect(UrlSafety::ipIsPublic('::ffff:100.64.0.1'))->toBeFalse();
    expect(UrlSafety::isSafeResolving('http://[::ffff:100.64.0.1]/x'))->toBeFalse();
    expect(UrlSafety::isSafeResolving('http://100.100.100.100/x'))->toBeFalse();
});

it('rejects ip-literal and localhost urls via isSafeResolving', function () {
    expect(UrlSafety::isSafeResolving('http://127.0.0.1/x'))->toBeFalse();
    expect(UrlSafety::isSafeResolving('http://169.254.169.254/latest/meta-data'))->toBeFalse();
    expect(UrlSafety::isSafeResolving('https://localhost/x'))->toBeFalse();
    expect(UrlSafety::isSafeResolving('ftp://example.com/x'))->toBeFalse();
    expect(UrlSafety::isSafeResolving(null))->toBeFalse();
});

it('refuses a host it cannot resolve instead of assuming there is no target', function () {
    // This used to pass, on the reasoning "the fetch then fails harmlessly, there is no
    // internal target". The inference is unsound: the client that connects does not have
    // to use this resolver, and the fetch happens later (queued job) than the check.
    $this->resolveHostTo('idp.test', []);

    expect(UrlSafety::hostIsPublic('idp.test'))->toBeFalse();
    expect(UrlSafety::isSafeResolving('https://idp.test/authorize'))->toBeFalse();
});

it('refuses numeric host encodings, judged by the real system resolver', function () {
    // The property production depends on is how the *C library* decodes these, so this
    // case deliberately runs on it rather than on a stub that could invent the answer.
    // Measured in the container: getaddrinfo's inet_aton path turns `2130706433` and
    // `127.1` into 127.0.0.1 without asking DNS at all, and refuses the hex forms.
    // Both outcomes must end in a refusal — one because the address is private, the
    // other because the fail-closed rule refuses an unresolvable name — and the class
    // must not be able to tell them apart.
    $this->useRealHostResolver();

    foreach (['0x7f000001', '0x7f.0.0.1', '0x7f.1', '2130706433', '127.1'] as $host) {
        expect(UrlSafety::hostIsPublic($host))->toBeFalse("{$host} was treated as public");
    }

    expect(UrlSafety::isSafeResolving('https://0x7f000001:9999/x'))->toBeFalse();
    expect(UrlSafety::isSafeResolving('http://2130706433/latest/meta-data/'))->toBeFalse();
});

it('refuses a hostname that resolves into a private range', function () {
    // The property the class's own docblock names as the reason isSafeResolving() exists,
    // and which had no coverage at any level: every SSRF test in the suite targeted an IP
    // literal, which short-circuits before the resolver, and the global stub made every
    // one of these names public.
    $internal = [
        'vault.internal',
        'gitlab.corp.internal',
        'metadata.google.internal',
        'kubernetes.default.svc.cluster.local',
        'consul.service.consul',
        'redis',
        'db',
    ];

    foreach ($internal as $host) {
        expect(UrlSafety::hostIsPublic($host))->toBeFalse("{$host} was treated as public")
            ->and(UrlSafety::isSafeResolving('https://'.$host.'/x'))->toBeFalse();
    }
});

it('judges a hostname by the addresses it resolves to', function () {
    $this->resolveHostTo('mirror.example.com', ['93.184.216.34']);
    expect(UrlSafety::hostIsPublic('mirror.example.com'))->toBeTrue();
    expect(UrlSafety::isSafeResolving('https://mirror.example.com/x'))->toBeTrue();

    // One private address among several is enough to refuse the name — a split-horizon
    // or dual-stack answer must not be judged on its friendliest entry.
    $this->resolveHostTo('mirror.example.com', ['93.184.216.34', '10.0.0.5']);
    expect(UrlSafety::hostIsPublic('mirror.example.com'))->toBeFalse();

    $this->resolveHostTo('mirror.example.com', ['93.184.216.34', 'fd00::1']);
    expect(UrlSafety::hostIsPublic('mirror.example.com'))->toBeFalse();
});

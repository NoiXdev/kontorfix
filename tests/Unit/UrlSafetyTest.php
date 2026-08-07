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

it('allows an unresolvable host (the http call fails harmlessly, no internal target)', function () {
    // *.test doesn't resolve → resolveIps is empty → passes (the fetch then fails).
    expect(UrlSafety::isSafeResolving('https://idp.test/authorize'))->toBeTrue();
});

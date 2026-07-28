<?php

use App\Services\Upstream\UrlSafety;

// Per RFC 3986, IPv6 literals in URLs are enclosed in square brackets:
// parse_url('http://[::1]/', PHP_URL_HOST) returns "[::1]" (WITH brackets).
// filter_var('[::1]', FILTER_VALIDATE_IP) therefore failed → the value was
// treated as a hostname and the SSRF range check was bypassed (Finding C2).
//
// These cases are pure IP literals: isSafeResolving() recognizes them as an IP
// and needs NO DNS → the test is hermetic (no network).
$unsafe = [
    'http://[::1]/',                        // IPv6 loopback
    'http://[::ffff:169.254.169.254]/x',    // IPv4-mapped cloud metadata
    'http://[fd00::1]/',                     // IPv6 ULA (private)
    'http://[fe80::1]/',                     // IPv6 link-local
    'http://[0:0:0:0:0:0:0:1]/',             // loopback written out in full
    'http://[::]/',                          // unspecified
    'http://[64:ff9b::a9fe:a9fe]/x',         // NAT64 → 169.254.169.254 (cloud metadata)
    'http://[64:ff9b::7f00:1]/',             // NAT64 → 127.0.0.1
    'http://[::127.0.0.1]/',                 // IPv4-compatible (deprecated) → loopback
];

it('rejects bracketed ipv6 literals that map to private/reserved space (isSafeResolving)', function () use ($unsafe) {
    foreach ($unsafe as $url) {
        expect(UrlSafety::isSafeResolving($url))->toBeFalse("sollte unsicher sein: {$url}");
    }
});

it('rejects bracketed ipv6 literals via the non-resolving core logic (isSafe)', function () use ($unsafe) {
    foreach ($unsafe as $url) {
        expect(UrlSafety::isSafe($url))->toBeFalse("sollte unsicher sein: {$url}");
    }
});

it('still allows a public bracketed ipv6 literal (hermetic, kein DNS)', function () {
    expect(UrlSafety::isSafeResolving('http://[2606:4700:4700::1111]/foo'))->toBeTrue();
    expect(UrlSafety::isSafe('http://[2606:4700:4700::1111]/foo'))->toBeTrue();
});

it('still allows legitimate public https upstreams (isSafe, kein DNS)', function () {
    // isSafe() does no DNS resolution → hermetic. Verifies that normalization
    // doesn't break ordinary hostnames.
    expect(UrlSafety::isSafe('https://repo.packagist.org/p2/foo.json'))->toBeTrue();
    expect(UrlSafety::isSafe('https://registry.npmjs.org/foo'))->toBeTrue();
});

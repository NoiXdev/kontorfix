<?php

use App\Services\Upstream\UrlSafety;

// IPv6-Literale in URLs stehen laut RFC 3986 in eckigen Klammern:
// parse_url('http://[::1]/', PHP_URL_HOST) liefert "[::1]" (MIT Klammern).
// filter_var('[::1]', FILTER_VALIDATE_IP) schlug daher fehl → der Wert wurde
// als Hostname behandelt und die SSRF-Range-Prüfung umgangen (Finding C2).
//
// Diese Fälle sind reine IP-Literale: isSafeResolving() erkennt sie als IP und
// braucht KEIN DNS → der Test ist hermetisch (kein Netzwerk).
$unsafe = [
    'http://[::1]/',                        // IPv6 loopback
    'http://[::ffff:169.254.169.254]/x',    // IPv4-mapped Cloud-Metadaten
    'http://[fd00::1]/',                     // IPv6 ULA (privat)
    'http://[fe80::1]/',                     // IPv6 link-local
    'http://[0:0:0:0:0:0:0:1]/',             // ausgeschriebenes loopback
    'http://[::]/',                          // unspecified
    'http://[64:ff9b::a9fe:a9fe]/x',         // NAT64 → 169.254.169.254 (Cloud-Metadaten)
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
    // isSafe() macht keine DNS-Auflösung → hermetisch. Prüft, dass die Normalisierung
    // gewöhnliche Hostnamen nicht kaputt macht.
    expect(UrlSafety::isSafe('https://repo.packagist.org/p2/foo.json'))->toBeTrue();
    expect(UrlSafety::isSafe('https://registry.npmjs.org/foo'))->toBeTrue();
});

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

it('rejects ip-literal and localhost urls via isSafeResolving', function () {
    expect(UrlSafety::isSafeResolving('http://127.0.0.1/x'))->toBeFalse();
    expect(UrlSafety::isSafeResolving('http://169.254.169.254/latest/meta-data'))->toBeFalse();
    expect(UrlSafety::isSafeResolving('https://localhost/x'))->toBeFalse();
    expect(UrlSafety::isSafeResolving('ftp://example.com/x'))->toBeFalse();
    expect(UrlSafety::isSafeResolving(null))->toBeFalse();
});

it('allows an unresolvable host (the http call fails harmlessly, no internal target)', function () {
    // *.test löst nicht auf → resolveIps ist leer → passiert (der Abruf schlägt dann fehl).
    expect(UrlSafety::isSafeResolving('https://idp.test/authorize'))->toBeTrue();
});

<?php

use App\Enums\VulnerabilitySeverity;
use App\Exceptions\ScannerException;
use App\Services\Scanner\HarborAdapterScanner;
use App\Services\Scanner\RegistryCredential;
use App\Services\Scanner\ScannerAddress;
use App\Services\Scanner\ScanTarget;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The client for the Harbor Pluggable Scanner Adapter API, against a faked adapter.
 *
 * Faked at the HTTP boundary rather than by swapping the interface, because the whole value
 * of this class IS the wire format: the headers it negotiates, the shape it posts, and the
 * media type it refuses to parse. A test that stubbed VulnerabilityScanner would assert
 * nothing this class is responsible for.
 */
beforeEach(function () {
    config([
        'kontorfix.scanner.enabled' => true,
        'kontorfix.scanner.url' => 'http://scanner:8080',
        'kontorfix.scanner.allowed_hosts' => ['scanner'],
        'kontorfix.scanner.timeout' => 60,
        'kontorfix.scanner.poll_interval' => 0,
    ]);
});

function scanTarget(): ScanTarget
{
    return new ScanTarget('3b/intern/meinapp', 'sha256:'.str_repeat('a', 64), 'latest');
}

function scanCredential(): RegistryCredential
{
    return new RegistryCredential('http://app:8080', 'kfx_secret');
}

it('reads the adapter identity from its metadata endpoint', function () {
    Http::fake(['scanner:8080/api/v1/metadata' => Http::response([
        'scanner' => ['name' => 'Trivy', 'vendor' => 'Aqua Security', 'version' => '0.50.1'],
        'capabilities' => [],
    ])]);

    $metadata = app(HarborAdapterScanner::class)->metadata();

    expect($metadata->name)->toBe('Trivy')->and($metadata->version)->toBe('0.50.1');

    Http::assertSent(fn (Request $r): bool => str_contains(
        $r->header('Accept')[0] ?? '', 'application/vnd.scanner.adapter.metadata+json'
    ));
});

it('hands the adapter our registry address and a bearer credential', function () {
    Http::fake(['scanner:8080/api/v1/scan' => Http::response(['id' => 'scan-1'], 202)]);

    $id = app(HarborAdapterScanner::class)->requestScan(scanTarget(), scanCredential());

    expect($id)->toBe('scan-1');

    Http::assertSent(function (Request $r): bool {
        $body = $r->data();

        return $body['registry']['url'] === 'http://app:8080'
            && $body['registry']['authorization'] === 'Bearer kfx_secret'
            && $body['artifact']['repository'] === '3b/intern/meinapp'
            && $body['artifact']['digest'] === 'sha256:'.str_repeat('a', 64);
    });
});

it('refuses a scan request the adapter did not accept', function () {
    Http::fake(['scanner:8080/api/v1/scan' => Http::response(['error' => ['message' => 'nope']], 500)]);

    app(HarborAdapterScanner::class)->requestScan(scanTarget(), scanCredential());
})->throws(ScannerException::class);

it('returns null while the report is not ready yet', function () {
    // The spec's own signal for "still running": 302 Found with a Location and no body.
    Http::fake(['scanner:8080/api/v1/scan/scan-1/report' => Http::response('', 302)]);

    expect(app(HarborAdapterScanner::class)->fetchReport('scan-1'))->toBeNull();
});

it('parses the normalised vulnerability report', function () {
    // Body is a JSON STRING, not an array: Http::response() forces Content-Type to
    // application/json whenever the body is an array (Factory::psr7Response() overwrites
    // the header key unconditionally in this exact shape), which would silently discard
    // the media type this test — and the client under test — cares about.
    Http::fake(['scanner:8080/api/v1/scan/scan-1/report' => Http::response(json_encode([
        'generated_at' => '2026-09-15T10:00:00Z',
        'severity' => 'Critical',
        'vulnerabilities' => [
            [
                'id' => 'CVE-2026-1000',
                'package' => 'openssl',
                'version' => '3.0.1',
                'fix_version' => '3.0.2',
                'severity' => 'Critical',
            ],
            [
                'id' => 'CVE-2026-1001',
                'package' => 'zlib',
                'version' => '1.2.11',
                'severity' => 'Medium',
            ],
        ],
    ]), 200, ['Content-Type' => 'application/vnd.security.vulnerability.report; version=1.1'])]);

    $report = app(HarborAdapterScanner::class)->fetchReport('scan-1');

    expect($report->findings)->toHaveCount(2)
        ->and($report->findings[0]->id)->toBe('CVE-2026-1000')
        ->and($report->findings[0]->severity)->toBe(VulnerabilitySeverity::Critical)
        ->and($report->findings[0]->fixedVersion)->toBe('3.0.2')
        // No fix published: null, never an empty string, so the UI can say "kein Fix
        // verfügbar" instead of rendering a blank cell that looks like a missing value.
        ->and($report->findings[1]->fixedVersion)->toBeNull();
});

it('refuses a report in a media type it does not read, naming the type', function () {
    // Scanner-proprietary formats are a non-goal: parsing one speculatively is how a
    // registry ends up bound to a single scanner, which is the opposite of the point.
    // Body is a JSON string for the same reason as above: an array body would make
    // Http::response() silently overwrite the very Content-Type this test sets.
    Http::fake(['scanner:8080/api/v1/scan/scan-1/report' => Http::response(
        json_encode(['whatever' => true], JSON_THROW_ON_ERROR), 200,
        ['Content-Type' => 'application/vnd.scanner.adapter.vuln.report.raw']
    )]);

    app(HarborAdapterScanner::class)->fetchReport('scan-1');
})->throws(ScannerException::class, 'vnd.scanner.adapter.vuln.report.raw');

it('refuses a report body it cannot decode, rather than reading it as a clean image', function () {
    // An empty findings list is a VERDICT — persisted as ok, rendered as "keine bekannten
    // Schwachstellen", never blocking. A body we could not parse must not become one.
    Http::fake(['scanner:8080/api/v1/scan/scan-1/report' => Http::response(
        '{"vulnerabilities": [', 200,
        ['Content-Type' => 'application/vnd.security.vulnerability.report; version=1.1']
    )]);

    app(HarborAdapterScanner::class)->fetchReport('scan-1');
})->throws(ScannerException::class);

it('does not follow a redirect off the scanner host', function () {
    // Every outbound hop is judged in this codebase (UrlSafety, AddressPin,
    // UpstreamClient::follow) — the last audit's only HIGH was a rebinding gap on exactly that
    // path. An adapter has no reason to redirect a scan request elsewhere, so the simpler and
    // stricter answer is to refuse rather than to re-judge.
    Http::fake(['scanner:8080/api/v1/metadata' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

    app(HarborAdapterScanner::class)->metadata();
})->throws(ScannerException::class);

it('refuses a scanner address that is neither public nor explicitly allowed', function () {
    // The SSRF policy is not widened for the scanner: its private address is reached only
    // because an operator named the host. An unnamed private address stays refused.
    config(['kontorfix.scanner.allowed_hosts' => []]);

    expect(ScannerAddress::isReachable('http://scanner:8080'))->toBeFalse()
        ->and(ScannerAddress::isReachable('http://192.168.1.5:8080'))->toBeFalse();

    config(['kontorfix.scanner.allowed_hosts' => ['scanner', '*.internal']]);

    expect(ScannerAddress::isReachable('http://scanner:8080'))->toBeTrue()
        ->and(ScannerAddress::isReachable('http://trivy.internal:8080'))->toBeTrue()
        ->and(ScannerAddress::isReachable('http://other-host:8080'))->toBeFalse();
});

it('refuses to speak to a scanner whose address is not permitted', function () {
    config(['kontorfix.scanner.allowed_hosts' => []]);
    Http::fake();

    expect(fn () => app(HarborAdapterScanner::class)->metadata())->toThrow(ScannerException::class);

    Http::assertNothingSent();
});

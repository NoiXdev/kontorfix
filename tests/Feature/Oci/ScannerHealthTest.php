<?php

use App\Enums\PackageType;
use App\Enums\ScanStatus;
use App\Exceptions\ScannerException;
use App\Models\OciManifest;
use App\Models\OciScanReport;
use App\Models\Package;
use App\Services\Health\HealthService;
use App\Services\Scanner\AdapterScanReport;
use App\Services\Scanner\RegistryCredential;
use App\Services\Scanner\ScannerMetadata;
use App\Services\Scanner\ScanTarget;
use App\Services\Scanner\VulnerabilityScanner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// Prefixed rather than bare names (`HealthScanner`, `scannerChecks()`): this is a Pest file,
// so both live as top-level globals for the whole test run once this file is loaded, and an
// unprefixed name is one collision away from a FATAL — not a failing assertion — the moment a
// second file wants the same name.
class ScannerHealthTestDouble implements VulnerabilityScanner
{
    public function __construct(private readonly ?ScannerException $failure = null) {}

    public function metadata(): ScannerMetadata
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new ScannerMetadata('Trivy', '0.50.1');
    }

    public function requestScan(ScanTarget $target, RegistryCredential $registry): string
    {
        return 'scan-1';
    }

    public function fetchReport(string $scanId): ?AdapterScanReport
    {
        return new AdapterScanReport([]);
    }
}

/**
 * Records the value of kontorfix.scanner.request_timeout AT THE MOMENT metadata() is called
 * — the one fact that proves the health check bounded itself independently of a raised
 * scan-submission budget, rather than merely asserting the config value was restored
 * afterwards (which a bug that never swapped it in the first place would also satisfy).
 */
class ScannerHealthTimeoutSpy implements VulnerabilityScanner
{
    public ?int $requestTimeoutDuringCall = null;

    public function metadata(): ScannerMetadata
    {
        $this->requestTimeoutDuringCall = (int) config('kontorfix.scanner.request_timeout');

        return new ScannerMetadata('Trivy', '0.50.1');
    }

    public function requestScan(ScanTarget $target, RegistryCredential $registry): string
    {
        return 'scan-1';
    }

    public function fetchReport(string $scanId): ?AdapterScanReport
    {
        return new AdapterScanReport([]);
    }
}

/** @return list<array{key:string,label:string,ok:bool,detail:string}> */
function scannerHealthChecks(): array
{
    return array_values(array_filter(
        app(HealthService::class)->checks(),
        fn (array $check): bool => str_starts_with($check['key'], 'scanner'),
    ));
}

it('reports nothing about a scanner nobody configured', function () {
    // An instance that does not scan must not grow a permanently red check for a feature it
    // deliberately does not use.
    config(['kontorfix.scanner.enabled' => false]);

    expect(scannerHealthChecks())->toBe([]);
});

it('reports the scanner it can reach', function () {
    config(['kontorfix.scanner.enabled' => true, 'kontorfix.scanner.url' => 'http://scanner:8080']);
    app()->instance(VulnerabilityScanner::class, new ScannerHealthTestDouble);

    $reachability = collect(scannerHealthChecks())->firstWhere('key', 'scanner');

    expect($reachability['ok'])->toBeTrue()->and($reachability['detail'])->toContain('Trivy');
});

it('goes red when the scanner cannot be reached', function () {
    config(['kontorfix.scanner.enabled' => true, 'kontorfix.scanner.url' => 'http://scanner:8080']);
    app()->instance(VulnerabilityScanner::class, new ScannerHealthTestDouble(ScannerException::notConfigured()));

    expect(collect(scannerHealthChecks())->firstWhere('key', 'scanner')['ok'])->toBeFalse();
});

it('goes red when the scanner connection is refused, not just when it answers badly', function () {
    // The ConnectionException path inside HarborAdapterScanner::metadata() was not exercised
    // by any earlier task's tests — Task 2 could not reach it and Task 3 did not either. The
    // health check is the first place a refused TCP connect to the scanner becomes observable
    // end to end, so it is exercised here against the REAL adapter (bound by
    // AppServiceProvider), not the double the other cases in this file use.
    config([
        'kontorfix.scanner.enabled' => true,
        'kontorfix.scanner.url' => 'http://scanner:8080',
        'kontorfix.scanner.allowed_hosts' => ['scanner'],
    ]);
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $reachability = collect(scannerHealthChecks())->firstWhere('key', 'scanner');

    expect($reachability['ok'])->toBeFalse()
        ->and($reachability['detail'])->toContain('nicht erreichbar');
});

it('bounds the reachability call independently of a raised scan-submission timeout', function () {
    // kontorfix.scanner.request_timeout is the SCAN-SUBMISSION budget, and an operator
    // running a genuinely slow scanner may legitimately raise it past what a route polled by
    // monitoring should ever wait for. The health check must not inherit that raised value —
    // it has its own, smaller budget (kontorfix.scanner.health_timeout).
    config([
        'kontorfix.scanner.enabled' => true,
        'kontorfix.scanner.url' => 'http://scanner:8080',
        'kontorfix.scanner.request_timeout' => 300,
        'kontorfix.scanner.health_timeout' => 5,
    ]);
    $spy = new ScannerHealthTimeoutSpy;
    app()->instance(VulnerabilityScanner::class, $spy);

    scannerHealthChecks();

    expect($spy->requestTimeoutDuringCall)->toBe(5)
        // And the raised value is not left clamped for whatever reads it after the check —
        // ScanRunner's own calls must still get the budget the operator configured.
        ->and(config('kontorfix.scanner.request_timeout'))->toBe(300);
});

it('goes red when nothing has been scanned successfully in a long time', function () {
    // A SCANNER THAT ANSWERS BUT NEVER UPDATES IS MORE DANGEROUS THAN NO SCANNER, because
    // it reports reassuring zeros. Reachability alone would be green for exactly that case,
    // which is why freshness is its own check.
    config(['kontorfix.scanner.enabled' => true, 'kontorfix.scanner.url' => 'http://scanner:8080']);
    app()->instance(VulnerabilityScanner::class, new ScannerHealthTestDouble);

    $package = Package::factory()->create(['type' => PackageType::Docker]);
    $manifest = OciManifest::factory()->for($package)->create();
    OciScanReport::factory()->for($manifest, 'manifest')->create([
        'status' => ScanStatus::Ok, 'scanned_at' => now()->subDays(21),
    ]);

    expect(collect(scannerHealthChecks())->firstWhere('key', 'scanner-freshness')['ok'])->toBeFalse();
});

it('stays green when the newest verdict is recent', function () {
    config(['kontorfix.scanner.enabled' => true, 'kontorfix.scanner.url' => 'http://scanner:8080']);
    app()->instance(VulnerabilityScanner::class, new ScannerHealthTestDouble);

    $package = Package::factory()->create(['type' => PackageType::Docker]);
    $manifest = OciManifest::factory()->for($package)->create();
    OciScanReport::factory()->for($manifest, 'manifest')->create([
        'status' => ScanStatus::Ok, 'scanned_at' => now()->subHours(6),
    ]);

    expect(collect(scannerHealthChecks())->firstWhere('key', 'scanner-freshness')['ok'])->toBeTrue();
});

it('stays green on an instance that simply has no images yet', function () {
    // Nothing to scan is not a failure, and a check that went red on a fresh install would
    // train the operator to ignore it.
    config(['kontorfix.scanner.enabled' => true, 'kontorfix.scanner.url' => 'http://scanner:8080']);
    app()->instance(VulnerabilityScanner::class, new ScannerHealthTestDouble);

    expect(collect(scannerHealthChecks())->firstWhere('key', 'scanner-freshness')['ok'])->toBeTrue();
});

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

class HealthScanner implements VulnerabilityScanner
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

/** @return list<array{key:string,label:string,ok:bool,detail:string}> */
function scannerChecks(): array
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

    expect(scannerChecks())->toBe([]);
});

it('reports the scanner it can reach', function () {
    config(['kontorfix.scanner.enabled' => true, 'kontorfix.scanner.url' => 'http://scanner:8080']);
    app()->instance(VulnerabilityScanner::class, new HealthScanner);

    $reachability = collect(scannerChecks())->firstWhere('key', 'scanner');

    expect($reachability['ok'])->toBeTrue()->and($reachability['detail'])->toContain('Trivy');
});

it('goes red when the scanner cannot be reached', function () {
    config(['kontorfix.scanner.enabled' => true, 'kontorfix.scanner.url' => 'http://scanner:8080']);
    app()->instance(VulnerabilityScanner::class, new HealthScanner(ScannerException::notConfigured()));

    expect(collect(scannerChecks())->firstWhere('key', 'scanner')['ok'])->toBeFalse();
});

it('goes red when the scanner connection is refused, not just when it answers badly', function () {
    // The ConnectionException path inside HarborAdapterScanner::metadata() was not exercised
    // by any earlier task's tests — Task 2 could not reach it and Task 3 did not either. The
    // health check is the first place a refused TCP connect to the scanner becomes observable
    // end to end, so it is exercised here against the REAL adapter (bound by
    // AppServiceProvider), not the HealthScanner double the other cases in this file use.
    config([
        'kontorfix.scanner.enabled' => true,
        'kontorfix.scanner.url' => 'http://scanner:8080',
        'kontorfix.scanner.allowed_hosts' => ['scanner'],
    ]);
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $reachability = collect(scannerChecks())->firstWhere('key', 'scanner');

    expect($reachability['ok'])->toBeFalse()
        ->and($reachability['detail'])->toContain('nicht erreichbar');
});

it('goes red when nothing has been scanned successfully in a long time', function () {
    // A SCANNER THAT ANSWERS BUT NEVER UPDATES IS MORE DANGEROUS THAN NO SCANNER, because
    // it reports reassuring zeros. Reachability alone would be green for exactly that case,
    // which is why freshness is its own check.
    config(['kontorfix.scanner.enabled' => true, 'kontorfix.scanner.url' => 'http://scanner:8080']);
    app()->instance(VulnerabilityScanner::class, new HealthScanner);

    $package = Package::factory()->create(['type' => PackageType::Docker]);
    $manifest = OciManifest::factory()->for($package)->create();
    OciScanReport::factory()->for($manifest, 'manifest')->create([
        'status' => ScanStatus::Ok, 'scanned_at' => now()->subDays(21),
    ]);

    expect(collect(scannerChecks())->firstWhere('key', 'scanner-freshness')['ok'])->toBeFalse();
});

it('stays green when the newest verdict is recent', function () {
    config(['kontorfix.scanner.enabled' => true, 'kontorfix.scanner.url' => 'http://scanner:8080']);
    app()->instance(VulnerabilityScanner::class, new HealthScanner);

    $package = Package::factory()->create(['type' => PackageType::Docker]);
    $manifest = OciManifest::factory()->for($package)->create();
    OciScanReport::factory()->for($manifest, 'manifest')->create([
        'status' => ScanStatus::Ok, 'scanned_at' => now()->subHours(6),
    ]);

    expect(collect(scannerChecks())->firstWhere('key', 'scanner-freshness')['ok'])->toBeTrue();
});

it('stays green on an instance that simply has no images yet', function () {
    // Nothing to scan is not a failure, and a check that went red on a fresh install would
    // train the operator to ignore it.
    config(['kontorfix.scanner.enabled' => true, 'kontorfix.scanner.url' => 'http://scanner:8080']);
    app()->instance(VulnerabilityScanner::class, new HealthScanner);

    expect(collect(scannerChecks())->firstWhere('key', 'scanner-freshness')['ok'])->toBeTrue();
});

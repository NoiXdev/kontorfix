<?php

use App\Enums\PackageType;
use App\Enums\ScanStatus;
use App\Enums\TokenAbility;
use App\Enums\VulnerabilitySeverity;
use App\Exceptions\ScannerException;
use App\Jobs\ScanOciArtifact;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciScanFinding;
use App\Models\OciScanReport;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Services\Scanner\AdapterScanReport;
use App\Services\Scanner\RegistryCredential;
use App\Services\Scanner\ScannedVulnerability;
use App\Services\Scanner\ScannerMetadata;
use App\Services\Scanner\ScanRunner;
use App\Services\Scanner\ScanTarget;
use App\Services\Scanner\VulnerabilityScanner;
use Illuminate\Support\Facades\Bus;

use function Pest\Laravel\travel;

/**
 * The scan as a whole: credential in, verdict out.
 *
 * The scanner is a hand-written fake implementing the interface rather than an HTTP fake —
 * Task 2 already proved the wire format, and what is under test here is everything AROUND
 * it: which registry the token is scoped to, that it is revoked afterwards, what the
 * adapter is told to pull and from where, and — the one that matters most — that a rescan
 * does not reset `first_seen_at`.
 */
class FakeScanner implements VulnerabilityScanner
{
    public ?ScanTarget $target = null;

    public ?RegistryCredential $credential = null;

    public int $pollsBeforeReady = 0;

    private int $polls = 0;

    /** @param list<ScannedVulnerability> $findings */
    public function __construct(public array $findings = [], public ?ScannerException $failWith = null) {}

    public function metadata(): ScannerMetadata
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return new ScannerMetadata('Trivy', '0.50.1');
    }

    public function requestScan(ScanTarget $target, RegistryCredential $registry): string
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->target = $target;
        $this->credential = $registry;

        return 'scan-1';
    }

    public function fetchReport(string $scanId): ?AdapterScanReport
    {
        if ($this->polls++ < $this->pollsBeforeReady) {
            return null;
        }

        return new AdapterScanReport($this->findings);
    }
}

function vuln(string $id, VulnerabilitySeverity $severity = VulnerabilitySeverity::High, string $package = 'openssl'): ScannedVulnerability
{
    return new ScannedVulnerability($id, $severity, $package, '3.0.1', '3.0.2');
}

function scannableManifest(): OciManifest
{
    $org = Organization::factory()->create(['slug' => '3b']);
    $group = Group::factory()->for($org)->create(['slug' => 'intern']);
    $package = Package::factory()->for($org)->create(['type' => PackageType::Docker, 'name' => 'meinapp']);
    $group->packages()->attach($package->id);

    return OciManifest::factory()->for($package)->create(['digest' => 'sha256:'.str_repeat('a', 64)]);
}

beforeEach(function () {
    config([
        'kontorfix.scanner.enabled' => true,
        'kontorfix.scanner.url' => 'http://scanner:8080',
        'kontorfix.scanner.registry_url' => 'http://app:8080',
        'kontorfix.scanner.allowed_hosts' => ['scanner'],
        'kontorfix.scanner.poll_interval' => 0,
        'kontorfix.scanner.timeout' => 60,
    ]);
});

it('records a verdict with its severity counts', function () {
    $manifest = scannableManifest();
    $scanner = new FakeScanner([
        vuln('CVE-2026-1', VulnerabilitySeverity::Critical),
        vuln('CVE-2026-2', VulnerabilitySeverity::High),
        vuln('CVE-2026-3', VulnerabilitySeverity::High, 'zlib'),
    ]);
    app()->instance(VulnerabilityScanner::class, $scanner);

    $report = app(ScanRunner::class)->run($manifest);

    expect($report->status)->toBe(ScanStatus::Ok)
        ->and($report->scanner_name)->toBe('Trivy')
        ->and($report->scanner_version)->toBe('0.50.1')
        ->and($report->scanned_at)->not->toBeNull()
        ->and($report->critical_count)->toBe(1)
        ->and($report->high_count)->toBe(2)
        ->and($report->findings()->count())->toBe(3);
});

it('hands the adapter the path-addressed repository and our in-network address', function () {
    $manifest = scannableManifest();
    $scanner = new FakeScanner;
    app()->instance(VulnerabilityScanner::class, $scanner);

    app(ScanRunner::class)->run($manifest);

    // Path addressing, never the custom-domain form: the adapter reaches us at app:8080,
    // where /v2/{org}/{registry}/{repo} is the only form that resolves.
    expect($scanner->target->repository)->toBe('3b/intern/meinapp')
        ->and($scanner->target->digest)->toBe('sha256:'.str_repeat('a', 64))
        ->and($scanner->credential->url)->toBe('http://app:8080');
});

it('mints a scanner token scoped to one registry and revokes it afterwards', function () {
    $manifest = scannableManifest();
    $scanner = new FakeScanner;
    app()->instance(VulnerabilityScanner::class, $scanner);

    app(ScanRunner::class)->run($manifest);

    $token = RegistryToken::where('for_scanner', true)->sole();

    expect($token->ability)->toBe(TokenAbility::Read)
        ->and($token->group_id)->not->toBeNull()
        ->and($token->expires_at)->not->toBeNull()
        // Revoked in a finally, so a crashed scan cannot leave a live credential behind.
        ->and($token->revoked_at)->not->toBeNull();
});

it('revokes the token even when the scan fails', function () {
    $manifest = scannableManifest();
    app()->instance(VulnerabilityScanner::class, new FakeScanner([], ScannerException::notConfigured()));

    app(ScanRunner::class)->run($manifest);

    expect(RegistryToken::where('for_scanner', true)->count())->toBe(1)
        ->and(RegistryToken::where('for_scanner', true)->whereNull('revoked_at')->count())->toBe(0);
});

it('records a failure when the scanner cannot be reached and serves nothing about it', function () {
    $manifest = scannableManifest();
    app()->instance(VulnerabilityScanner::class, new FakeScanner([], ScannerException::notConfigured()));

    $report = app(ScanRunner::class)->run($manifest);

    expect($report->status)->toBe(ScanStatus::Failed)
        ->and($report->error)->toContain('Scanner')
        ->and($report->failed_at)->not->toBeNull()
        ->and($report->scanned_at)->toBeNull()
        ->and($report->findings()->count())->toBe(0);
});

it('keeps a good verdict when a later rescan fails', function () {
    // THE RULE A FAILED RESCAN MUST NOT BREAK. If a failure overwrote a verdict that had
    // already succeeded, stopping the scanner container would unblock every artifact on the
    // instance at once — an outage turned into a bypass, reachable by anyone who can kill a
    // container. A failure records itself beside the good report and leaves it alone.
    $manifest = scannableManifest();
    app()->instance(VulnerabilityScanner::class, new FakeScanner([vuln('CVE-2026-1')]));
    app(ScanRunner::class)->run($manifest);

    app()->instance(VulnerabilityScanner::class, new FakeScanner([], ScannerException::notConfigured()));
    $report = app(ScanRunner::class)->run($manifest)->fresh();

    expect($report->status)->toBe(ScanStatus::Ok)
        ->and($report->findings()->count())->toBe(1)
        ->and($report->failed_at)->not->toBeNull()
        ->and($report->error)->not->toBeNull()
        ->and($report->isStale())->toBeTrue();
});

it('does not reset first_seen_at when a rescan finds the same vulnerability', function () {
    // THE MUTATION-CHECKED TEST. `first_seen_at` is the entire mechanism of the grace
    // period: reset it on every rescan and the clock restarts nightly, so nothing ever
    // reaches its grace and blocking silently degrades to "off" with no other test failing.
    $manifest = scannableManifest();
    app()->instance(VulnerabilityScanner::class, new FakeScanner([vuln('CVE-2026-1')]));
    app(ScanRunner::class)->run($manifest);

    $originalFirstSeen = OciScanFinding::sole()->first_seen_at;

    travel(30)->days();
    app()->instance(VulnerabilityScanner::class, new FakeScanner([vuln('CVE-2026-1')]));
    app(ScanRunner::class)->run($manifest);

    expect(OciScanFinding::sole()->first_seen_at->timestamp)->toBe($originalFirstSeen->timestamp);
});

it('stamps first_seen_at now for a vulnerability that was not there before', function () {
    $manifest = scannableManifest();
    app()->instance(VulnerabilityScanner::class, new FakeScanner([vuln('CVE-2026-1')]));
    app(ScanRunner::class)->run($manifest);

    travel(30)->days();
    app()->instance(VulnerabilityScanner::class, new FakeScanner([vuln('CVE-2026-1'), vuln('CVE-2026-2')]));
    app(ScanRunner::class)->run($manifest);

    $fresh = OciScanFinding::where('vulnerability_id', 'CVE-2026-2')->sole();

    expect($fresh->first_seen_at->isToday())->toBeTrue();
});

it('drops a finding a rescan no longer reports', function () {
    $manifest = scannableManifest();
    app()->instance(VulnerabilityScanner::class, new FakeScanner([vuln('CVE-2026-1'), vuln('CVE-2026-2')]));
    app(ScanRunner::class)->run($manifest);

    app()->instance(VulnerabilityScanner::class, new FakeScanner([vuln('CVE-2026-1')]));
    app(ScanRunner::class)->run($manifest);

    expect(OciScanFinding::pluck('vulnerability_id')->all())->toBe(['CVE-2026-1']);
});

it('keeps polling until the report is ready', function () {
    $manifest = scannableManifest();
    $scanner = new FakeScanner([vuln('CVE-2026-1')]);
    $scanner->pollsBeforeReady = 3;
    app()->instance(VulnerabilityScanner::class, $scanner);

    expect(app(ScanRunner::class)->run($manifest)->status)->toBe(ScanStatus::Ok);
});

it('fails the scan when the repository is in no registry', function () {
    // Nothing to scope a token to and no address to hand the adapter. Reported as a failure
    // naming the cause, rather than swallowed as "no findings" — which would render as a
    // clean image.
    $package = Package::factory()->create(['type' => PackageType::Docker]);
    $manifest = OciManifest::factory()->for($package)->create();
    app()->instance(VulnerabilityScanner::class, new FakeScanner);

    $report = app(ScanRunner::class)->run($manifest);

    expect($report->status)->toBe(ScanStatus::Failed)
        ->and($report->error)->toContain('Registry');
});

it('runs one job per manifest however many tags point at it', function () {
    Bus::fake();

    $manifest = scannableManifest();

    ScanOciArtifact::dispatch($manifest->id);
    ScanOciArtifact::dispatch($manifest->id);

    // ShouldBeUnique keyed on the manifest: ten tags on one image are one scan, not ten.
    Bus::assertDispatchedTimes(ScanOciArtifact::class, 1);
});

it('does nothing at all while scanning is switched off', function () {
    config(['kontorfix.scanner.enabled' => false]);

    $manifest = scannableManifest();
    app()->instance(VulnerabilityScanner::class, new FakeScanner([vuln('CVE-2026-1')]));

    app(ScanRunner::class)->run($manifest);

    expect(OciScanReport::count())->toBe(0);
});

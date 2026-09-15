<?php

use App\Enums\ScanStatus;
use App\Enums\VulnerabilitySeverity;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciScanFinding;
use App\Models\OciScanReport;
use App\Models\Organization;
use App\Models\RegistryToken;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The shape the whole feature rests on. Two things here are load-bearing beyond "the
 * columns exist":
 *
 *  - `severity_rank` is a denormalised copy of `severity`, kept so the blocking rule is an
 *    indexed integer comparison on the `docker pull` hot path rather than a string set.
 *    `OciScanFinding::booted()` is what keeps the copy honest — it derives the rank on every
 *    save, so no caller can set it independently — and the test below asserts exactly that
 *    by trying to store a mismatched rank and checking the model overrules it.
 *  - `first_seen_at` is a plain column with no default, because the only correct value is
 *    decided by ScanReportWriter (Task 3) and a database default would quietly make "now"
 *    look right on a rescan.
 */
it('stores a report per manifest and scanner', function () {
    $manifest = OciManifest::factory()->create();

    $report = OciScanReport::factory()->for($manifest, 'manifest')->create([
        'scanner_name' => 'Trivy',
        'scanner_version' => '0.50.1',
        'status' => ScanStatus::Ok,
        'critical_count' => 2,
    ]);

    expect($report->status)->toBe(ScanStatus::Ok)
        ->and($report->manifest->is($manifest))->toBeTrue()
        ->and($report->critical_count)->toBe(2);
});

it('refuses two reports from one scanner for one manifest', function () {
    // Under RefreshDatabase this whole test runs inside one Postgres transaction, and a
    // failed statement aborts that transaction outright — anything issued afterwards,
    // including the framework's own teardown queries, would raise a second, confusing
    // error ("current transaction is aborted"). Running the second insert inside its own
    // DB::transaction() gives it a savepoint instead: Postgres rolls back only that
    // savepoint on failure, the outer test transaction stays healthy, and RefreshDatabase's
    // teardown proceeds normally. The assertion is still exactly "the second insert is
    // refused by the unique key".
    $manifest = OciManifest::factory()->create();
    OciScanReport::factory()->for($manifest, 'manifest')->create(['scanner_name' => 'Trivy']);

    expect(fn () => DB::transaction(
        fn () => OciScanReport::factory()->for($manifest, 'manifest')->create(['scanner_name' => 'Trivy'])
    ))->toThrow(QueryException::class);
});

it('lets one scanner report the same CVE against two packages', function () {
    $report = OciScanReport::factory()->create();

    OciScanFinding::factory()->for($report, 'report')->create([
        'vulnerability_id' => 'CVE-2026-1000', 'package_name' => 'openssl',
    ]);
    OciScanFinding::factory()->for($report, 'report')->create([
        'vulnerability_id' => 'CVE-2026-1000', 'package_name' => 'libcrypto3',
    ]);

    expect($report->findings()->count())->toBe(2);
});

it('deletes findings with their report and reports with their manifest', function () {
    $manifest = OciManifest::factory()->create();
    $report = OciScanReport::factory()->for($manifest, 'manifest')->create();
    OciScanFinding::factory()->for($report, 'report')->create();

    $manifest->delete();

    expect(OciScanReport::count())->toBe(0)
        ->and(OciScanFinding::count())->toBe(0);
});

it('derives severity_rank from severity, whatever a caller tries to store', function () {
    // The guard for the denormalisation, and it has to be able to fail: a row is written
    // with a rank that contradicts its severity, and the model overrules it. Asserting that
    // an already-consistent row stays consistent would pass whether or not anything enforced
    // it — which is what this test used to do.
    $finding = OciScanFinding::factory()->create(['severity' => VulnerabilitySeverity::Low]);
    $finding->forceFill(['severity_rank' => 99])->save();

    expect($finding->fresh()->severity_rank)->toBe(VulnerabilitySeverity::Low->rank());

    foreach (VulnerabilitySeverity::cases() as $severity) {
        $row = OciScanFinding::factory()->create(['severity' => $severity]);

        expect($row->fresh()->severity_rank)->toBe($severity->rank());
    }
});

it('orders severities from unknown up to critical', function () {
    expect(VulnerabilitySeverity::Critical->rank())->toBeGreaterThan(VulnerabilitySeverity::High->rank())
        ->and(VulnerabilitySeverity::High->rank())->toBeGreaterThan(VulnerabilitySeverity::Medium->rank())
        ->and(VulnerabilitySeverity::Medium->rank())->toBeGreaterThan(VulnerabilitySeverity::Low->rank())
        ->and(VulnerabilitySeverity::Low->rank())->toBeGreaterThan(VulnerabilitySeverity::Unknown->rank())
        ->and(VulnerabilitySeverity::High->atLeast(VulnerabilitySeverity::Medium))->toBeTrue()
        ->and(VulnerabilitySeverity::Medium->atLeast(VulnerabilitySeverity::High))->toBeFalse()
        ->and(VulnerabilitySeverity::High->atLeast(VulnerabilitySeverity::High))->toBeTrue();
});

it('reads an adapter severity whatever case it arrives in, and never guesses', function () {
    // Trivy sends "CRITICAL"; the spec's normalised report type does not promise a case.
    // Anything unrecognised — including null — becomes Unknown rather than throwing: a
    // severity we cannot read must not fail a whole scan, and Unknown is below every
    // threshold an operator can set, so it can never block on its own.
    expect(VulnerabilitySeverity::fromAdapter('CRITICAL'))->toBe(VulnerabilitySeverity::Critical)
        ->and(VulnerabilitySeverity::fromAdapter('high'))->toBe(VulnerabilitySeverity::High)
        ->and(VulnerabilitySeverity::fromAdapter('NEGLIGIBLE'))->toBe(VulnerabilitySeverity::Unknown)
        ->and(VulnerabilitySeverity::fromAdapter(null))->toBe(VulnerabilitySeverity::Unknown);
});

it('defaults a registry to no blocking and a seven-day grace', function () {
    $group = Group::factory()->create();

    expect($group->scan_block_severity)->toBeNull()
        ->and($group->scan_block_grace_days)->toBe(7);
});

it('casts a registry threshold to the severity enum', function () {
    $group = Group::factory()->create(['scan_block_severity' => VulnerabilitySeverity::High]);

    expect($group->fresh()->scan_block_severity)->toBe(VulnerabilitySeverity::High);
});

it('defaults a registry token to not being a scanner token', function () {
    $org = Organization::factory()->create();
    [$token] = RegistryToken::issue($org, 'test', null);

    expect($token->fresh()->for_scanner)->toBeFalse();
});

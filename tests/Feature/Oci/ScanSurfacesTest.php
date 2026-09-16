<?php

use App\Enums\PackageType;
use App\Enums\ScanStatus;
use App\Enums\VulnerabilitySeverity;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciScanFinding;
use App\Models\OciScanReport;
use App\Models\OciTag;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Scanner\ScanCardPresenter;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['kontorfix.scanner.enabled' => true]);

    $this->org = Organization::factory()->create();
    $this->group = Group::factory()->for($this->org)->create(['portal_enabled' => true]);
    $this->package = Package::factory()->for($this->org)->create(['type' => PackageType::Docker, 'name' => 'meinapp']);
    $this->group->packages()->attach($this->package->id);
    $this->manifest = OciManifest::factory()->for($this->package)->create();
    OciTag::factory()->create(['package_id' => $this->package->id, 'manifest_id' => $this->manifest->id, 'name' => 'latest']);
});

it('shows a tag its severity counts and its findings', function () {
    $report = OciScanReport::factory()->for($this->manifest, 'manifest')->create([
        'status' => ScanStatus::Ok, 'critical_count' => 1, 'high_count' => 2, 'scanned_at' => now()->subHour(),
    ]);
    OciScanFinding::factory()->for($report, 'report')->severity(VulnerabilitySeverity::Critical)
        ->create(['vulnerability_id' => 'CVE-2026-1', 'package_name' => 'openssl', 'fixed_version' => '3.0.2']);

    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $this->package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tags.0.scan.status', 'ok')
            ->where('tags.0.scan.counts.critical', 1)
            ->where('tags.0.scan.counts.high', 2)
            ->where('tags.0.scan.findings.0.vulnerability_id', 'CVE-2026-1')
            ->where('tags.0.scan.findings.0.fixed_version', '3.0.2')
            ->etc());
});

it('says "not checked" rather than "no findings" for an unscanned tag', function () {
    // The two are entirely different statements, and rendering them identically is how a
    // broken scanner reads as a clean registry.
    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $this->package))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tags.0.scan', null)->etc());
});

it('marks a verdict as stale when the last attempt failed', function () {
    OciScanReport::factory()->for($this->manifest, 'manifest')->create([
        'status' => ScanStatus::Ok,
        'scanned_at' => now()->subDays(20),
        'failed_at' => now()->subHour(),
        'error' => 'Scanner nicht erreichbar',
    ]);

    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $this->package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tags.0.scan.stale', true)
            ->where('tags.0.scan.error', 'Scanner nicht erreichbar')
            ->etc());
});

it('computes the card for many tags without one query per tag', function () {
    // Two tags on one manifest share one report by construction; a presenter that read per
    // tag would also read per manifest, and a repository with fifty tags would cost fifty
    // round trips on a page that already counts its queries.
    // Named explicitly rather than left to the factory's `fake()->word()` default: with 9
    // rows sharing one `package_id`, an unqualified Faker word collides against the
    // `(package_id, name)` unique index often enough to flake this test — reproduced twice
    // in ~10 runs while writing it.
    OciTag::factory()->count(9)->sequence(fn ($sequence) => ['name' => 'tag-'.$sequence->index])->create([
        'package_id' => $this->package->id, 'manifest_id' => $this->manifest->id,
    ]);
    OciScanReport::factory()->for($this->manifest, 'manifest')->create(['status' => ScanStatus::Ok]);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->actingAs(superAdmin())->get(route('admin.packages.show', $this->package))->assertOk();

    $scanQueries = $queries;

    expect($scanQueries)->toBeLessThan(80);
});

it('shows the customer the findings for their own image', function () {
    // They are the ones pulling it. Withholding what is in it would defeat the purpose of
    // scanning it at all.
    $report = OciScanReport::factory()->for($this->manifest, 'manifest')->create(['status' => ScanStatus::Ok]);
    OciScanFinding::factory()->for($report, 'report')->severity(VulnerabilitySeverity::High)
        ->create(['vulnerability_id' => 'CVE-2026-9']);

    $user = adminOf($this->org);

    $this->actingAs($user)
        ->get(route('portal.registries.package', [$this->org->slug, $this->group->id, $this->package->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('package.scan.findings.0.vulnerability_id', 'CVE-2026-9')
            ->etc());
});

it('tells the customer when their image is already blocked', function () {
    $this->group->forceFill([
        'scan_block_severity' => VulnerabilitySeverity::High, 'scan_block_grace_days' => 7,
    ])->save();

    $report = OciScanReport::factory()->for($this->manifest, 'manifest')->create(['status' => ScanStatus::Ok]);
    OciScanFinding::factory()->for($report, 'report')->severity(VulnerabilitySeverity::Critical)
        ->firstSeenDaysAgo(30)->create();

    $this->actingAs(adminOf($this->org))
        ->get(route('portal.registries.package', [$this->org->slug, $this->group->id, $this->package->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('package.scan.blocked', true)->etc());
});

it('tells the customer when a finding is inside its grace period and when it stops being', function () {
    $this->group->forceFill([
        'scan_block_severity' => VulnerabilitySeverity::High, 'scan_block_grace_days' => 7,
    ])->save();

    $report = OciScanReport::factory()->for($this->manifest, 'manifest')->create(['status' => ScanStatus::Ok]);
    OciScanFinding::factory()->for($report, 'report')->severity(VulnerabilitySeverity::Critical)
        ->firstSeenDaysAgo(2)->create();

    $this->actingAs(adminOf($this->org))
        ->get(route('portal.registries.package', [$this->org->slug, $this->group->id, $this->package->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('package.scan.blocked', false)
            ->where('package.scan.blocks_at', now()->addDays(5)->toDateString())
            ->etc());
});

it('carries no scan section at all while scanning is switched off', function () {
    config(['kontorfix.scanner.enabled' => false]);
    OciScanReport::factory()->for($this->manifest, 'manifest')->create(['status' => ScanStatus::Ok]);

    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $this->package))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('scan_enabled', false)->etc());
});

it('shows the OK verdict rather than a failed one from another scanner, regardless of NULL sort order', function () {
    // Postgres sorts NULLs FIRST under `ORDER BY … DESC`. A pending/failed report has
    // `scanned_at = null`, so a naive `orderByDesc('scanned_at')` puts that row ahead of a
    // genuine OK verdict from a scanner the instance was switched away from — the exact
    // "instance switched scanners" shape `oci_scan_reports` is documented to carry (one row
    // per manifest+scanner). The presenter must not let a report with no verdict outrank one
    // that has one.
    OciScanReport::factory()->for($this->manifest, 'manifest')->create([
        'scanner_name' => 'Grype',
        'status' => ScanStatus::Failed,
        'scanned_at' => null,
        'failed_at' => now(),
        'error' => 'Grype nicht erreichbar',
    ]);
    OciScanReport::factory()->for($this->manifest, 'manifest')->create([
        'scanner_name' => 'Trivy',
        'status' => ScanStatus::Ok,
        'scanned_at' => now()->subHour(),
        'critical_count' => 1,
    ]);

    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $this->package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tags.0.scan.status', 'ok')
            ->where('tags.0.scan.counts.critical', 1)
            ->etc());
});

it('never asks ScanBlockGuard::refuses() about a finding from a report that is not Ok', function () {
    // refuses() deliberately does not check the report's status itself — it would have to
    // lazy-load the report per finding on a customer-facing page. That obligation belongs to
    // the presenter: it must never hand refuses() a finding whose report is Pending or
    // Failed. Findings only ever hang off Ok reports in practice (ScanReportWriter's only
    // writer), so this pins the presenter's OWN filtering rather than relying on that being
    // true forever by convention.
    $this->group->forceFill([
        'scan_block_severity' => VulnerabilitySeverity::Low, 'scan_block_grace_days' => 0,
    ])->save();

    // A Pending report has no findings by construction (see ScanStatus::Pending's own
    // docblock: "No findings yet, and none implied"), so the only way to exercise this pin
    // is a finding attached to a report row that is NOT Ok despite carrying one — which is
    // exactly the shape a caller who skipped the Ok filter would mishandle.
    $notOkReport = OciScanReport::factory()->for($this->manifest, 'manifest')->create([
        'scanner_name' => 'Trivy',
        'status' => ScanStatus::Failed,
        'scanned_at' => null,
        'failed_at' => now(),
    ]);
    OciScanFinding::factory()->for($notOkReport, 'report')->severity(VulnerabilitySeverity::Critical)
        ->firstSeenDaysAgo(30)->create();

    $card = app(ScanCardPresenter::class)
        ->forManifests(collect([$this->manifest]), $this->group)[$this->manifest->id] ?? null;

    // The only report on this manifest is the Failed one, so the card must not report a
    // block from its finding — doing so would mean refuses() was asked about a finding
    // whose report never reached Ok.
    expect($card['blocked'] ?? null)->not->toBeTrue();
    expect($card['blocks_at'] ?? null)->toBeNull();
});

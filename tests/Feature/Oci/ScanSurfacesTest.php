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
use App\Services\Scanner\ScanBlockGuard;
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
            ->where('scans.'.$this->manifest->id.'.status', 'ok')
            ->where('scans.'.$this->manifest->id.'.counts.critical', 1)
            ->where('scans.'.$this->manifest->id.'.counts.high', 2)
            ->where('scans.'.$this->manifest->id.'.findings.0.vulnerability_id', 'CVE-2026-1')
            ->where('scans.'.$this->manifest->id.'.findings.0.fixed_version', '3.0.2')
            ->etc());
});

it('says "not checked" rather than "no findings" for an unscanned tag', function () {
    // The two are entirely different statements, and rendering them identically is how a
    // broken scanner reads as a clean registry. The tag still names its manifest — the page
    // looks the card up by that id — and the absence of an entry under `scans` is what the
    // component reads as NOT CHECKED.
    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $this->package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tags.0.manifest_id', $this->manifest->id)
            ->where('scans', [])
            ->etc());
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
            ->where('scans.'.$this->manifest->id.'.stale', true)
            ->where('scans.'.$this->manifest->id.'.error', 'Scanner nicht erreichbar')
            ->etc());
});

it('costs the same number of queries for ten tags on one manifest as for three', function () {
    // The INVARIANT, not a ceiling. Two tags on one manifest share one report by
    // construction, so the page's read count must scale with distinct MANIFESTS and not
    // with how many names point at them.
    //
    // A ceiling alone could not fail for the regression it was named after: this page
    // measured 29 queries with 10 tags, the old assertion was "< 40", and a full per-tag
    // reader costs about +1 per tag — landing at 39 and passing. Equality at two different
    // tag counts is unsatisfiable for a per-tag reader at ANY ceiling. The absolute bound
    // is kept alongside it, for growth that is per-PAGE rather than per-tag.
    //
    // Tag names are given explicitly rather than left to the factory's `fake()->word()`
    // default: with several rows sharing one `package_id`, an unqualified Faker word
    // collides against the `(package_id, name)` unique index often enough to flake.
    OciScanReport::factory()->for($this->manifest, 'manifest')->create(['status' => ScanStatus::Ok]);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $admin = superAdmin();

    $render = function () use ($admin, &$queries): int {
        $queries = 0;
        $this->actingAs($admin)->get(route('admin.packages.show', $this->package))->assertOk();

        return $queries;
    };

    // Discarded: the very first render of the run also pays for whatever the session and
    // permission layers memoise once, which is not a per-tag cost and would otherwise show
    // up as a difference between the two measurements below.
    $render();

    // `beforeEach` already created `latest`.
    OciTag::factory()->count(2)->sequence(fn ($sequence) => ['name' => 'few-'.$sequence->index])->create([
        'package_id' => $this->package->id, 'manifest_id' => $this->manifest->id,
    ]);
    $withThreeTags = $render();

    OciTag::factory()->count(7)->sequence(fn ($sequence) => ['name' => 'many-'.$sequence->index])->create([
        'package_id' => $this->package->id, 'manifest_id' => $this->manifest->id,
    ]);
    $withTenTags = $render();

    expect($withTenTags)->toBe($withThreeTags);
    // Measured at 20 for a warmed-up render of this page. Tight enough to notice a new
    // per-page read, and the equality above is what actually guards the per-tag shape.
    expect($withTenTags)->toBeLessThan(25);
});

it('sends one scan card per manifest, not one per tag that names it', function () {
    // The cost this shape exists to remove: 20 tags over 15 manifests at ~800 findings each
    // put ~16,000 finding objects into the page JSON, behind a collapsed toggle almost
    // nobody opens. The card is keyed by manifest and the tag rows carry only the id.
    OciTag::factory()->count(4)->sequence(fn ($sequence) => ['name' => 'alias-'.$sequence->index])->create([
        'package_id' => $this->package->id, 'manifest_id' => $this->manifest->id,
    ]);
    OciScanReport::factory()->for($this->manifest, 'manifest')->create(['status' => ScanStatus::Ok]);

    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $this->package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tags', 5)
            ->has('scans', 1)
            ->where('tags.0.manifest_id', $this->manifest->id)
            // The card itself never rides along on a tag row.
            ->missing('tags.0.scan')
            ->etc());
});

it('caps the findings it embeds and says how many it is not showing', function () {
    // `counts` is the exact statement of how many there are; the list is the worst of them.
    // `findings_total` is what keeps the capped table from reading as the complete one.
    $report = OciScanReport::factory()->for($this->manifest, 'manifest')->create([
        'status' => ScanStatus::Ok, 'high_count' => 40,
    ]);
    OciScanFinding::factory()->count(40)->for($report, 'report')->severity(VulnerabilitySeverity::High)
        ->sequence(fn ($sequence) => ['vulnerability_id' => 'CVE-2026-'.$sequence->index])
        ->create();

    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $this->package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scans.'.$this->manifest->id.'.findings_total', 40)
            ->where('scans.'.$this->manifest->id.'.counts.high', 40)
            ->has('scans.'.$this->manifest->id.'.findings', 25)
            ->etc());
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

it('hides the portal scan card too while scanning is switched off instance-wide', function () {
    // The admin page hides its whole scan column behind `scan_enabled` (the test above) —
    // without the same flag on the portal, every Docker repository would show a permanent
    // "Noch nicht geprüft" card implying a check is merely pending, on an instance that
    // never scans at all.
    config(['kontorfix.scanner.enabled' => false]);
    OciScanReport::factory()->for($this->manifest, 'manifest')->create(['status' => ScanStatus::Ok]);

    $this->actingAs(adminOf($this->org))
        ->get(route('portal.registries.package', [$this->org->slug, $this->group->id, $this->package->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('scan_enabled', false)->etc());
});

it('shows a pending scan as "in progress", not as a blank card indistinguishable from unscanned', function () {
    // ScanStatus's three states must not render identically: this one is neither "nobody
    // has looked yet" (the report row would not exist at all — see the "not checked" test
    // above) nor "we looked and it failed" — it is a scan in flight. The row this pins is
    // the DATA the card is built from; the Vue side of this fix (ScanFindings.vue actually
    // showing `status_label` for `pending`) has no dedicated test per this project's "no
    // component-mounting tests" convention, but it has nothing to render without this.
    OciScanReport::factory()->for($this->manifest, 'manifest')->create([
        'status' => ScanStatus::Pending, 'scanned_at' => null,
    ]);

    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $this->package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scans.'.$this->manifest->id.'.status', 'pending')
            ->where('scans.'.$this->manifest->id.'.status_label', 'Prüfung läuft')
            ->etc());
});

it('withholds the operator-internal scanner identity and error text from the portal, but not from the admin console', function () {
    // isStale() reaches this the moment a good verdict goes stale: `status === Ok &&
    // failed_at !== null`. The `error` column is written from ScanRunner's caught
    // exceptions, which embed the internal scanner URL, the KONTORFIX_SCANNER_URL /
    // KONTORFIX_SCANNER_ALLOWED_HOSTS env var names, an operator instruction and raw cURL
    // text — none of it this customer's business merely because their image is stale.
    $rawError = 'Der Scanner unter http://scanner.intern.example:8080 ist nicht erreichbar: cURL error 7';

    $report = OciScanReport::factory()->for($this->manifest, 'manifest')->create([
        'status' => ScanStatus::Ok,
        'scanner_name' => 'Trivy',
        'scanner_version' => '0.50.1',
        'scanned_at' => now()->subDays(20),
        'failed_at' => now()->subHour(),
        'error' => $rawError,
    ]);
    OciScanFinding::factory()->for($report, 'report')->create();

    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $this->package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scans.'.$this->manifest->id.'.stale', true)
            ->where('scans.'.$this->manifest->id.'.error', $rawError)
            ->where('scans.'.$this->manifest->id.'.scanner', 'Trivy 0.50.1')
            ->etc());

    $this->actingAs(adminOf($this->org))
        ->get(route('portal.registries.package', [$this->org->slug, $this->group->id, $this->package->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('package.scan.stale', true)
            ->where('package.scan.scanner', null)
            // Not merely a DIFFERENT string — no substring of the operator's internals may
            // survive into the portal's replacement text: not the host, not the env var
            // names, not the word "cURL".
            ->where('package.scan.error', fn (?string $error): bool => $error !== null
                && ! str_contains($error, 'scanner.intern.example')
                && ! str_contains($error, 'KONTORFIX_SCANNER')
                && ! str_contains($error, 'cURL')
                && $error !== $rawError)
            ->etc());
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
            ->where('scans.'.$this->manifest->id.'.status', 'ok')
            ->where('scans.'.$this->manifest->id.'.counts.critical', 1)
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

    // Asserted as an array FIRST: `expect(null['blocked'] ?? null)->not->toBeTrue()` would
    // pass just as happily if forManifests() returned no card at all for this manifest,
    // which would pin nothing about refuses() ever being asked the right question.
    expect($card)->toBeArray();
    // The only report on this manifest is the Failed one, so the card must not report a
    // block from its finding — doing so would mean refuses() was asked about a finding
    // whose report never reached Ok.
    expect($card['blocked'])->toBeFalse();
    expect($card['blocks_at'])->toBeNull();
});

it('derives blocks_at from whichever finding crosses the threshold soonest, not the worst-severity one', function () {
    // The failure mode this pins: a lower-severity finding known far longer can graduate to
    // blocking before a just-discovered higher-severity one does. A presenter that named
    // the worse SEVERITY's own date (this class's first cut, and ScanBlockGuard::preview()'s
    // before this fix) could show `blocked: true` right next to a `blocks_at` that still
    // reads as the future — the exact self-contradiction
    // ScanBlockGuard::earliestBlockAt() exists to prevent.
    $this->group->forceFill([
        'scan_block_severity' => VulnerabilitySeverity::High, 'scan_block_grace_days' => 7,
    ])->save();

    $report = OciScanReport::factory()->for($this->manifest, 'manifest')->create(['status' => ScanStatus::Ok]);
    // Worst severity, but its own grace has not expired — the finding a naive
    // "worst-severity-first" selection would (wrongly) name and date.
    OciScanFinding::factory()->for($report, 'report')->severity(VulnerabilitySeverity::Critical)
        ->firstSeenDaysAgo(1)->create(['vulnerability_id' => 'CVE-CRITICAL-RECENT']);
    // Lower severity, known far longer — this is the one actually refusing the pull today.
    OciScanFinding::factory()->for($report, 'report')->severity(VulnerabilitySeverity::High)
        ->firstSeenDaysAgo(30)->create(['vulnerability_id' => 'CVE-HIGH-OLD']);

    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $this->package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scans.'.$this->manifest->id.'.blocked', true)
            ->where('scans.'.$this->manifest->id.'.blocks_at', now()->subDays(23)->toDateString())
            ->etc());
});

it('agrees with ScanBlockGuard::preview() on blocks_at for the same manifest', function () {
    // preview() made the identical worst-severity-first mistake before this fix — both
    // callers now go through the one shared ScanBlockGuard::earliestBlockAt(), so this
    // pins that the operator's settings preview and the customer-facing card cannot drift
    // apart on the same data again.
    $this->group->forceFill([
        'scan_block_severity' => VulnerabilitySeverity::High, 'scan_block_grace_days' => 7,
    ])->save();

    $report = OciScanReport::factory()->for($this->manifest, 'manifest')->create(['status' => ScanStatus::Ok]);
    OciScanFinding::factory()->for($report, 'report')->severity(VulnerabilitySeverity::Critical)
        ->firstSeenDaysAgo(1)->create();
    OciScanFinding::factory()->for($report, 'report')->severity(VulnerabilitySeverity::High)
        ->firstSeenDaysAgo(30)->create();

    $card = app(ScanCardPresenter::class)
        ->forManifests(collect([$this->manifest]), $this->group)[$this->manifest->id];

    $preview = app(ScanBlockGuard::class)->preview($this->group, VulnerabilitySeverity::High, 7);

    expect($card['blocked'])->toBe($preview['artifacts'][0]['blocked']);
    expect($card['blocks_at'])->toBe($preview['artifacts'][0]['blocks_at']);
});

it('unions findings across every Ok report on the manifest when deciding blocked, not just the newest scanner\'s', function () {
    // A scanner swap otherwise leaves an older scanner's still-unexpired Critical invisible
    // to `blocked` while the actual pull-time guard (which reads every Ok report on the
    // manifest, not merely the latest) keeps refusing it — exactly the disagreement this
    // whole feature exists to prevent.
    $this->group->forceFill([
        'scan_block_severity' => VulnerabilitySeverity::High, 'scan_block_grace_days' => 7,
    ])->save();

    $oldReport = OciScanReport::factory()->for($this->manifest, 'manifest')->create([
        'scanner_name' => 'OldScanner', 'status' => ScanStatus::Ok, 'scanned_at' => now()->subDays(60),
    ]);
    OciScanFinding::factory()->for($oldReport, 'report')->severity(VulnerabilitySeverity::Critical)
        ->firstSeenDaysAgo(40)->create();

    // The NEWEST report — the one status/counts/the displayed findings come FROM — carries
    // nothing that would block on its own.
    OciScanReport::factory()->for($this->manifest, 'manifest')->create([
        'scanner_name' => 'NewScanner', 'status' => ScanStatus::Ok, 'scanned_at' => now()->subHour(),
    ]);

    $this->actingAs(superAdmin())
        ->get(route('admin.packages.show', $this->package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scans.'.$this->manifest->id.'.scanner', 'NewScanner 0.50.1')
            ->where('scans.'.$this->manifest->id.'.blocked', true)
            ->etc());
});

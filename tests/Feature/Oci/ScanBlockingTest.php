<?php

use App\Enums\PackageType;
use App\Enums\ScanStatus;
use App\Enums\TokenAbility;
use App\Enums\VulnerabilitySeverity;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciScanFinding;
use App\Models\OciScanReport;
use App\Models\OciTag;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Services\Scanner\ScanBlockGuard;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The five states of the blocking rule, all reachable through a real `docker pull`.
 *
 * Exercised through the HTTP endpoint rather than against the guard alone, because half of
 * what this feature promises is the SHAPE of the refusal: a bare 403 makes the Docker client
 * print "unknown error", which leaves the customer with a broken build and nothing to act on.
 *
 * Prefixed rather than bare (`blockingFixture()`, `pullManifest()`, `okFinding()`): this is a
 * Pest file, so every top-level declaration in it lives as a GLOBAL for the whole test run
 * once the file is loaded, and a collision with a second file wanting the same name is a
 * FATAL, not a failing assertion. Same discipline as ScannerHealthTest's own prefixes.
 *
 * @return array{0: Group, 1: Package, 2: OciManifest}
 */
function scanBlockingFixture(): array
{
    $org = Organization::factory()->create(['slug' => '3b']);
    $group = Group::factory()->for($org)->create(['slug' => 'intern']);
    $package = Package::factory()->for($org)->create(['type' => PackageType::Docker, 'name' => 'meinapp']);
    $group->packages()->attach($package->id);

    $manifest = OciManifest::factory()->for($package)->create([
        'digest' => 'sha256:'.str_repeat('b', 64),
        'media_type' => 'application/vnd.oci.image.manifest.v1+json',
        'payload' => '{"schemaVersion":2}',
    ]);
    OciTag::factory()->create([
        'package_id' => $package->id, 'manifest_id' => $manifest->id, 'name' => 'latest',
    ]);

    return [$group, $package, $manifest];
}

/** @return TestResponse<Response> */
function scanBlockingPull(Group $group, string $reference, ?string $plainToken = null): TestResponse
{
    return test()->call(
        'GET',
        '/v2/3b/intern/meinapp/manifests/'.$reference,
        server: ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('x:'.($plainToken ?? tokenPlainTextFor($group)))],
    );
}

function scanBlockingOkFinding(OciManifest $manifest, VulnerabilitySeverity $severity, int $daysKnown): OciScanFinding
{
    $report = OciScanReport::factory()->for($manifest, 'manifest')->create(['status' => ScanStatus::Ok]);

    return OciScanFinding::factory()->for($report, 'report')
        ->severity($severity)
        ->firstSeenDaysAgo($daysKnown)
        ->create();
}

it('serves an artifact nobody has scanned', function () {
    // The one place this design deliberately does not fail closed. The alternative is that
    // switching a threshold on takes the registry down until the scan backlog drains — a
    // self-inflicted outage at the moment of enabling.
    [$group] = scanBlockingFixture();
    $group->update(['scan_block_severity' => VulnerabilitySeverity::Low, 'scan_block_grace_days' => 0]);

    scanBlockingPull($group, 'latest')->assertOk();
});

it('serves an artifact whose only scan failed', function () {
    [$group, , $manifest] = scanBlockingFixture();
    $group->update(['scan_block_severity' => VulnerabilitySeverity::Low, 'scan_block_grace_days' => 0]);
    OciScanReport::factory()->for($manifest, 'manifest')->create([
        'status' => ScanStatus::Failed, 'scanned_at' => null, 'error' => 'Scanner nicht erreichbar',
    ]);

    scanBlockingPull($group, 'latest')->assertOk();
});

it('serves an artifact whose findings are all below the threshold', function () {
    [$group, , $manifest] = scanBlockingFixture();
    $group->update(['scan_block_severity' => VulnerabilitySeverity::Critical, 'scan_block_grace_days' => 0]);
    scanBlockingOkFinding($manifest, VulnerabilitySeverity::High, 90);

    scanBlockingPull($group, 'latest')->assertOk();
});

it('serves an artifact whose finding is still inside its grace period', function () {
    [$group, , $manifest] = scanBlockingFixture();
    $group->update(['scan_block_severity' => VulnerabilitySeverity::High, 'scan_block_grace_days' => 7]);
    scanBlockingOkFinding($manifest, VulnerabilitySeverity::Critical, 3);

    scanBlockingPull($group, 'latest')->assertOk();
});

it('refuses an artifact whose finding is past its grace period, with an OCI error body', function () {
    [$group, , $manifest] = scanBlockingFixture();
    $group->update(['scan_block_severity' => VulnerabilitySeverity::High, 'scan_block_grace_days' => 7]);
    $f = scanBlockingOkFinding($manifest, VulnerabilitySeverity::Critical, 30);

    $response = scanBlockingPull($group, 'latest')->assertStatus(403);

    // A well-formed OCI envelope, not a bare 403 — this string is what the Docker client
    // prints straight into the customer's terminal, so it has to name the vulnerability.
    expect($response->json('errors.0.code'))->toBe('DENIED')
        ->and($response->json('errors.0.message'))->toContain($f->vulnerability_id)
        ->and($response->headers->get('Docker-Distribution-Api-Version'))->toBe('registry/2.0');
});

it('refuses a digest-addressed pull of the same artifact', function () {
    // Tag or digest, the same manifest resolves — so the same refusal has to apply, or the
    // rule is bypassed by the address form every CI system actually uses.
    [$group, , $manifest] = scanBlockingFixture();
    $group->update(['scan_block_severity' => VulnerabilitySeverity::High, 'scan_block_grace_days' => 7]);
    scanBlockingOkFinding($manifest, VulnerabilitySeverity::Critical, 30);

    scanBlockingPull($group, $manifest->digest)->assertStatus(403);
});

it('names the worst finding when several would block', function () {
    [$group, , $manifest] = scanBlockingFixture();
    $group->update(['scan_block_severity' => VulnerabilitySeverity::Medium, 'scan_block_grace_days' => 7]);

    $report = OciScanReport::factory()->for($manifest, 'manifest')->create(['status' => ScanStatus::Ok]);
    OciScanFinding::factory()->for($report, 'report')->severity(VulnerabilitySeverity::Medium)
        ->firstSeenDaysAgo(30)->create(['vulnerability_id' => 'CVE-MEDIUM']);
    OciScanFinding::factory()->for($report, 'report')->severity(VulnerabilitySeverity::Critical)
        ->firstSeenDaysAgo(30)->create(['vulnerability_id' => 'CVE-CRITICAL']);

    expect(scanBlockingPull($group, 'latest')->json('errors.0.message'))->toContain('CVE-CRITICAL');
});

it('serves a blocked artifact to a scanner token', function () {
    // WITHOUT THIS THE FEATURE IS A ONE-WAY DOOR. The adapter pulls the artifact over the
    // very endpoint the rule refuses, so a blocked artifact could never be rescanned — and
    // therefore never unblocked, not by a withdrawn advisory, not by a corrected severity,
    // not by anything.
    [$group, , $manifest] = scanBlockingFixture();
    $group->update(['scan_block_severity' => VulnerabilitySeverity::High, 'scan_block_grace_days' => 7]);
    scanBlockingOkFinding($manifest, VulnerabilitySeverity::Critical, 30);

    [$token, $plain] = RegistryToken::issue($group->organization, 'scanner', $group, TokenAbility::Read);
    $token->forceFill(['for_scanner' => true])->save();

    scanBlockingPull($group, 'latest', $plain)->assertOk();
});

it('does not exempt an ordinary read token', function () {
    [$group, , $manifest] = scanBlockingFixture();
    $group->update(['scan_block_severity' => VulnerabilitySeverity::High, 'scan_block_grace_days' => 7]);
    scanBlockingOkFinding($manifest, VulnerabilitySeverity::Critical, 30);

    scanBlockingPull($group, 'latest')->assertStatus(403);
});

it('keeps refusing a pull while the instance-wide scanner is switched off', function () {
    // `kontorfix.scanner.enabled` gates only whether NEW scans are dispatched (see
    // ScanOciArtifact and the "Jetzt prüfen" action) — it is never consulted on the pull
    // path. An operator who removes the scanner container must not have every previously
    // recorded finding start passing through: that would turn a scanner outage into a
    // blanket bypass of every threshold every registry on the instance has configured,
    // which is exactly the "outage becomes a bypass" property the grace-period rule exists
    // to prevent (see ScanBlockGuard's own docblock).
    config(['kontorfix.scanner.enabled' => false]);
    [$group, , $manifest] = scanBlockingFixture();
    $group->update(['scan_block_severity' => VulnerabilitySeverity::High, 'scan_block_grace_days' => 7]);
    scanBlockingOkFinding($manifest, VulnerabilitySeverity::Critical, 30);

    scanBlockingPull($group, 'latest')->assertStatus(403);
});

it('asks the database nothing while the registry does not block', function () {
    // The rule runs on EVERY manifest resolution, and blocking is off by default — so the
    // default has to cost nothing at all, not one cheap query.
    [$group, , $manifest] = scanBlockingFixture();
    scanBlockingOkFinding($manifest, VulnerabilitySeverity::Critical, 30);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    expect(app(ScanBlockGuard::class)->blockingFinding($manifest, $group))->toBeNull()
        ->and($queries)->toBe(0);
});

it('applies each registry its own threshold for one shared manifest', function () {
    // The verdict belongs to the artifact; the threshold belongs to the registry. A
    // repository assigned to two registries can legitimately be blocked in one and served
    // in the other.
    [$strict, $package, $manifest] = scanBlockingFixture();
    $strict->update(['scan_block_severity' => VulnerabilitySeverity::High, 'scan_block_grace_days' => 7]);
    scanBlockingOkFinding($manifest, VulnerabilitySeverity::Critical, 30);

    $lenient = Group::factory()->for($strict->organization)->create(['slug' => 'offen']);
    $lenient->packages()->attach($package->id);

    expect(app(ScanBlockGuard::class)->blockingFinding($manifest, $strict))->not->toBeNull()
        ->and(app(ScanBlockGuard::class)->blockingFinding($manifest, $lenient))->toBeNull();
});

it('previews what a proposed threshold would block, and when', function () {
    [$group, , $manifest] = scanBlockingFixture();
    scanBlockingOkFinding($manifest, VulnerabilitySeverity::Critical, 30);

    $preview = app(ScanBlockGuard::class)->preview($group, VulnerabilitySeverity::High, 7);

    expect($preview['blocking_now'])->toBe(1)
        ->and($preview['blocking_later'])->toBe(0)
        ->and($preview['artifacts'][0]['package'])->toBe('meinapp')
        ->and($preview['artifacts'][0]['tags'])->toBe(['latest'])
        ->and($preview['artifacts'][0]['blocked'])->toBeTrue();
});

it('separates what would block now from what would block later', function () {
    [$group, , $manifest] = scanBlockingFixture();
    scanBlockingOkFinding($manifest, VulnerabilitySeverity::Critical, 2);

    $preview = app(ScanBlockGuard::class)->preview($group, VulnerabilitySeverity::High, 7);

    expect($preview['blocking_now'])->toBe(0)
        ->and($preview['blocking_later'])->toBe(1)
        ->and($preview['artifacts'][0]['blocked'])->toBeFalse()
        ->and($preview['artifacts'][0]['blocks_at'])->not->toBeNull();
});

it('previews nothing when no threshold is proposed', function () {
    [$group, , $manifest] = scanBlockingFixture();
    scanBlockingOkFinding($manifest, VulnerabilitySeverity::Critical, 30);

    expect(app(ScanBlockGuard::class)->preview($group, null, 7)['artifacts'])->toBe([]);
});

/**
 * The guard states its rule twice on purpose: once in SQL (`blockingFinding`, for the pull
 * path) and once in memory (`refuses`, for Task 7's ScanCardPresenter to restate over
 * already-loaded findings). If those two ever drift, the customer portal tells a customer
 * their image is fine while the registry refuses to serve it — so this test asserts the two
 * paths AGREE at the boundary rather than checking each against its own independently
 * written expectation.
 *
 * Time is frozen for the whole test: the boundary finding's `first_seen_at` is written as
 * `now()->subDays(7)` and the guard later computes its own cutoff as `now()->subDays(7)` —
 * without freezing, those two `now()` calls land at different instants (the write happens
 * strictly before the read), so the row would always be a hair PAST the cutoff and the
 * `<=` half of the rule would never actually be exercised at `=`. Freezing makes the two
 * calls resolve to the same instant, which is the only way "exactly at the boundary" is a
 * real condition here rather than "some very small amount of time past it".
 *
 * Frozen at the START of a second, specifically, not at whatever microsecond `now()` happens
 * to land on: `OciScanFinding`'s `first_seen_at` cast round-trips new values through
 * Carbon/Eloquent's `Y-m-d H:i:s` datetime format the moment they are set — with no database
 * round-trip needed to see it — which truncates sub-second precision on the IN-MEMORY model
 * attribute. Freezing at an arbitrary microsecond would make `$atBoundary->first_seen_at`
 * (truncated) compare as strictly BEFORE `now()` (not truncated) even at the intended
 * boundary, which silently hides the exact instant this test exists to pin. Starting the
 * frozen instant at a whole second removes that gap: there is no fractional part left to lose.
 *
 * The two findings get their OWN manifest and report apiece: `blockingFinding()` returns
 * only the single worst-then-earliest row for a manifest, so putting both findings on one
 * manifest would make it answer only for whichever one sorts first, leaving the other
 * checked against nothing but `refuses()`'s own output — exactly the "two independently
 * written expectations" shape this test exists to avoid.
 */
it('agrees between the SQL guard and the in-memory guard at the exact boundary', function () {
    $this->travelTo(now()->startOfSecond());

    [$group, $package, $manifestAtBoundary] = scanBlockingFixture();
    $group->update(['scan_block_severity' => VulnerabilitySeverity::High, 'scan_block_grace_days' => 7]);

    // Severity exactly at the threshold, first_seen_at exactly at the grace cutoff — the
    // rule is "at or above" / "at or before", so this finding must block under both paths.
    $reportAtBoundary = OciScanReport::factory()->for($manifestAtBoundary, 'manifest')->create(['status' => ScanStatus::Ok]);
    $atBoundary = OciScanFinding::factory()->for($reportAtBoundary, 'report')
        ->severity(VulnerabilitySeverity::High)
        ->create(['first_seen_at' => now()->subDays(7), 'vulnerability_id' => 'CVE-AT-BOUNDARY']);

    // A second, independent manifest one second inside the grace period — must NOT block
    // under either path.
    $manifestInsideGrace = OciManifest::factory()->for($package)->create([
        'digest' => 'sha256:'.str_repeat('e', 64),
    ]);
    $reportInsideGrace = OciScanReport::factory()->for($manifestInsideGrace, 'manifest')->create(['status' => ScanStatus::Ok]);
    $insideGrace = OciScanFinding::factory()->for($reportInsideGrace, 'report')
        ->severity(VulnerabilitySeverity::High)
        ->create(['first_seen_at' => now()->subDays(7)->addSecond(), 'vulnerability_id' => 'CVE-INSIDE-GRACE']);

    $guard = app(ScanBlockGuard::class);

    expect($guard->refuses($atBoundary, $group))
        ->toBe($guard->blockingFinding($manifestAtBoundary, $group) !== null)
        ->and($guard->refuses($atBoundary, $group))->toBeTrue()
        ->and($guard->refuses($insideGrace, $group))
        ->toBe($guard->blockingFinding($manifestInsideGrace, $group) !== null)
        ->and($guard->refuses($insideGrace, $group))->toBeFalse();
});

it('leaves out an artifact this registry no longer serves', function () {
    // `Group::assignedPackages()` names itself the one statement of the serve-time
    // predicate, and every other caller asks IT. A preview counting an assignment that
    // expired yesterday tells the operator they are about to affect an image this registry
    // already refuses to address — which is exactly the guessing the preview exists to
    // remove.
    [$group, $package, $manifest] = scanBlockingFixture();
    scanBlockingOkFinding($manifest, VulnerabilitySeverity::Critical, 30);

    $group->packages()->updateExistingPivot($package->id, ['available_until' => now()->subDay()]);

    $preview = app(ScanBlockGuard::class)->preview($group, VulnerabilitySeverity::High, 7);

    expect($preview['blocking_now'])->toBe(0)
        ->and($preview['blocking_later'])->toBe(0)
        ->and($preview['artifacts'])->toBe([]);
});

it('caps the artifacts it names while counting the whole registry exactly', function () {
    // The preview runs on a debounced keystroke, up to ten times a minute per operator,
    // against the same Postgres the pull path uses — and a Debian-based image carries
    // 500-1500 CVEs. Loading every qualifying finding of every manifest was a five- to
    // six-figure hydration per request. The LIST is a bounded illustration; the two numbers
    // the operator acts on are exact, and `artifacts_total` is what lets the screen say how
    // much it is not showing.
    [$group, $package] = scanBlockingFixture();

    $blockingNow = 55;
    $blockingLater = 4;

    foreach (range(1, $blockingNow + $blockingLater) as $index) {
        $manifest = OciManifest::factory()->for($package)->create([
            'digest' => 'sha256:'.str_pad((string) $index, 64, '0', STR_PAD_LEFT),
        ]);

        scanBlockingOkFinding(
            $manifest,
            VulnerabilitySeverity::Critical,
            $index <= $blockingNow ? 30 : 1,
        );
    }

    $preview = app(ScanBlockGuard::class)->preview($group, VulnerabilitySeverity::High, 7);

    expect($preview['blocking_now'])->toBe($blockingNow)
        ->and($preview['blocking_later'])->toBe($blockingLater)
        ->and($preview['artifacts_total'])->toBe($blockingNow + $blockingLater)
        // Capped, and never silently: ScanBlocking.vue renders the difference as
        // "… und N weitere".
        ->and($preview['artifacts'])->toHaveCount(50);
});

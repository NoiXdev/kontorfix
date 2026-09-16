<?php

use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Jobs\ScanOciArtifact;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciScanReport;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

beforeEach(function () {
    config([
        'kontorfix.scanner.enabled' => true,
        'kontorfix.scanner.url' => 'http://scanner:8080',
        'kontorfix.scanner.allowed_hosts' => ['scanner'],
    ]);
});

/** @return array{0: Group, 1: Package} */
function scanTriggerFixture(): array
{
    $org = Organization::factory()->create(['slug' => '3b']);
    $group = Group::factory()->for($org)->create(['slug' => 'intern']);
    $package = Package::factory()->for($org)->create(['type' => PackageType::Docker, 'name' => 'meinapp']);
    $group->packages()->attach($package->id);

    return [$group, $package];
}

it('queues a scan when a manifest is pushed', function () {
    Queue::fake();
    [$group, $package] = scanTriggerFixture();

    $payload = json_encode(['schemaVersion' => 2, 'layers' => []], JSON_THROW_ON_ERROR);

    $this->call(
        'PUT',
        '/v2/3b/intern/meinapp/manifests/latest',
        server: ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('x:'.tokenPlainTextFor($group, TokenAbility::Publish)),
            'CONTENT_TYPE' => 'application/vnd.oci.image.manifest.v1+json'],
        content: $payload,
    )->assertStatus(201);

    Queue::assertPushed(ScanOciArtifact::class);
});

it('queues nothing on push while scanning is switched off', function () {
    Queue::fake();
    config(['kontorfix.scanner.enabled' => false]);
    [$group] = scanTriggerFixture();

    $this->call(
        'PUT',
        '/v2/3b/intern/meinapp/manifests/latest',
        server: ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('x:'.tokenPlainTextFor($group, TokenAbility::Publish)),
            'CONTENT_TYPE' => 'application/vnd.oci.image.manifest.v1+json'],
        content: json_encode(['schemaVersion' => 2], JSON_THROW_ON_ERROR),
    )->assertStatus(201);

    Queue::assertNothingPushed();
});

it('rescans the never-scanned first, then the stalest', function () {
    Queue::fake();
    [, $package] = scanTriggerFixture();

    $never = OciManifest::factory()->for($package)->create();
    $old = OciManifest::factory()->for($package)->create();
    $fresh = OciManifest::factory()->for($package)->create();

    OciScanReport::factory()->for($old, 'manifest')->create(['scanned_at' => now()->subDays(30)]);
    OciScanReport::factory()->for($fresh, 'manifest')->create(['scanned_at' => now()]);

    artisan('oci:scan', ['--limit' => 2])->assertSuccessful();

    Queue::assertPushed(ScanOciArtifact::class, fn (ScanOciArtifact $j): bool => $j->manifestId === $never->id);
    Queue::assertPushed(ScanOciArtifact::class, fn (ScanOciArtifact $j): bool => $j->manifestId === $old->id);
    Queue::assertNotPushed(ScanOciArtifact::class, fn (ScanOciArtifact $j): bool => $j->manifestId === $fresh->id);
});

it('says how many manifests the budget left behind', function () {
    // Hitting the budget is reported, never silent — the same discipline oci:sweep applies.
    // A bounded run that printed nothing would read as "everything is scanned".
    Queue::fake();
    [, $package] = scanTriggerFixture();
    OciManifest::factory()->count(5)->for($package)->create();

    artisan('oci:scan', ['--limit' => 2])
        ->expectsOutputToContain('3')
        ->assertSuccessful();
});

it('scans nothing for a non-Docker package', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $package = Package::factory()->for($org)->create(['type' => PackageType::Composer]);
    OciManifest::factory()->for($package)->create();

    artisan('oci:scan')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('queues a manifest carrying two report rows only once', function () {
    // oci_scan_reports is one row per (manifest, scanner) by design — a manifest that has
    // been scanned by two different scanners (e.g. an instance switched adapters) legitimately
    // carries two report rows. A naive leftJoin against that table multiplies the manifest in
    // the candidate query: it appears once per report row, inflating $total and letting
    // ShouldBeUnique quietly absorb a duplicate dispatch that should never have been queued —
    // spending two slots of the run's limit on the very same manifest.
    Queue::fake();
    [, $package] = scanTriggerFixture();

    $manifest = OciManifest::factory()->for($package)->create();
    OciScanReport::factory()->for($manifest, 'manifest')->create([
        'scanner_name' => 'Trivy',
        'scanned_at' => now()->subDays(10),
    ]);
    OciScanReport::factory()->for($manifest, 'manifest')->create([
        'scanner_name' => 'Grype',
        'scanned_at' => now()->subDays(5),
    ]);

    artisan('oci:scan', ['--limit' => 10])
        ->expectsOutputToContain('1 Manifest')
        ->assertSuccessful();

    Queue::assertPushed(ScanOciArtifact::class, 1);
});

it('lets an administrator trigger a scan for one digest', function () {
    Queue::fake();
    [, $package] = scanTriggerFixture();
    $manifest = OciManifest::factory()->for($package)->create();

    $this->actingAs(superAdmin())
        ->post(route('admin.packages.scan', $package), ['digest' => $manifest->digest])
        ->assertRedirect();

    Queue::assertPushed(ScanOciArtifact::class, fn (ScanOciArtifact $j): bool => $j->manifestId === $manifest->id);
});

it('refuses an on-demand scan from someone who does not administer the organization', function () {
    Queue::fake();
    [, $package] = scanTriggerFixture();
    $manifest = OciManifest::factory()->for($package)->create();

    $outsider = adminOf(Organization::factory()->create());

    $this->actingAs($outsider)
        ->post(route('admin.packages.scan', $package), ['digest' => $manifest->digest])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('refuses an on-demand scan for a digest in a different repository', function () {
    // The digest is caller-supplied and a digest is only unique WITHIN a repository, so it
    // is resolved against THIS package rather than looked up globally.
    Queue::fake();
    [, $package] = scanTriggerFixture();
    $foreign = OciManifest::factory()->create();

    // postJson(), not post(): every other 422-validation assertion in this codebase (see
    // GitCredentialHostBindingTest, PackageProbeTest) drives the request as JSON, because a
    // plain, non-XHR post() never satisfies Request::expectsJson() and so a ValidationException
    // redirects (302, errors in the session) instead of answering 422 — which is also what a
    // real Inertia form post looks like server-side, since Inertia's client issues an XHR.
    $this->actingAs(superAdmin())
        ->postJson(route('admin.packages.scan', $package), ['digest' => $foreign->digest])
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

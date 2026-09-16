<?php

use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Jobs\ScanOciArtifact;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciScanReport;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Contracts\Bus\Dispatcher;
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

it('still stores the manifest and answers 201 when queueing the scan fails', function () {
    // The manifest is already committed by the time ScanOciArtifact::dispatch() runs (see
    // the comment at ManifestController::put()) — a queue outage must not turn a push whose
    // bytes are already safely stored into a 500 the client reads as "the push failed".
    //
    // Deliberately NOT Queue::fake(): a fake queue never throws, so it cannot exercise the
    // try/catch at all. The failure is injected one layer down, at the Bus dispatcher
    // PendingDispatch::__destruct() hands the job to, which is what a real queue-connection
    // outage (Redis unreachable, …) would actually throw through.
    [$group, $package] = scanTriggerFixture();

    $this->mock(Dispatcher::class, function ($mock) {
        $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('queue unavailable'));
    });

    $payload = json_encode(['schemaVersion' => 2, 'layers' => []], JSON_THROW_ON_ERROR);

    $this->call(
        'PUT',
        '/v2/3b/intern/meinapp/manifests/latest',
        server: ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('x:'.tokenPlainTextFor($group, TokenAbility::Publish)),
            'CONTENT_TYPE' => 'application/vnd.oci.image.manifest.v1+json'],
        content: $payload,
    )->assertStatus(201);

    expect(OciManifest::where('package_id', $package->id)->exists())->toBeTrue();
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

it('refuses a non-positive --limit instead of silently emptying the budget', function () {
    // `Builder::limit()` ignores a negative value outright, which would otherwise enqueue
    // every candidate manifest in one tick — precisely what a budget exists to prevent. A
    // non-zero exit here is the loud failure a typo'd cron entry deserves.
    Queue::fake();
    [, $package] = scanTriggerFixture();
    OciManifest::factory()->for($package)->create();

    artisan('oci:scan', ['--limit' => -1])->assertFailed();

    Queue::assertNothingPushed();
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

    // The load-bearing assertion is the OUTPUT, not the push count below: QueueFake enforces
    // ShouldBeUnique itself, so `assertPushed(ScanOciArtifact::class, 1)` would hold even
    // against the buggy leftJoin — it only proves the DISPATCH was deduplicated, not that the
    // candidate query counted and budgeted the manifest once. Matched against the exact
    // "eingereiht" sentence (not a bare '1 Manifest' substring) so this can never pass by
    // accidentally matching the "N Manifest(e) bleiben ... übrig" warning line instead.
    artisan('oci:scan', ['--limit' => 10])
        ->expectsOutputToContain('1 Manifest(e) zur Prüfung eingereiht.')
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

it('refuses an on-demand scan while scanning is switched off globally', function () {
    // Dispatching anyway would flash "eingereiht" while ScanRunner::run() returns null
    // without ever writing a verdict — the operator would wait for a result that can never
    // arrive. Same guard, same German wording as oci:scan's own refusal.
    Queue::fake();
    config(['kontorfix.scanner.enabled' => false]);
    [, $package] = scanTriggerFixture();
    $manifest = OciManifest::factory()->for($package)->create();

    $this->actingAs(superAdmin())
        ->post(route('admin.packages.scan', $package), ['digest' => $manifest->digest])
        ->assertStatus(409);

    Queue::assertNothingPushed();
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
    // GitCredentialHostBindingTest, PackageProbeTest) drives the request as JSON. A plain,
    // non-XHR post() never satisfies Request::expectsJson() either, for a DIFFERENT reason
    // than a real console submit would: Inertia's own client also sends `Accept:
    // text/html, application/xhtml+xml, ...` plus `X-Inertia: true` rather than a JSON
    // Accept header, so `expectsJson()` is false for it too — a real Task-6 button click
    // takes the 302 + session-error-bag branch below, not this one. postJson() here
    // exercises the validation rule itself as directly as possible; the console's actual
    // request shape is covered by the next test.
    $this->actingAs(superAdmin())
        ->postJson(route('admin.packages.scan', $package), ['digest' => $foreign->digest])
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

it('answers a plain form submission for a foreign digest with a redirect and a session error', function () {
    // This is the shape Task 6's "Jetzt prüfen" button actually produces server-side: an
    // Inertia visit is still, from Laravel's point of view, a non-JSON-accepting request
    // (see the comment above), so ValidationException takes its default path — redirect
    // back with the error in the session — and Inertia's client renders it as a form error
    // without a full navigation.
    Queue::fake();
    [, $package] = scanTriggerFixture();
    $foreign = OciManifest::factory()->create();

    $this->actingAs(superAdmin())
        ->post(route('admin.packages.scan', $package), ['digest' => $foreign->digest])
        ->assertRedirect()
        ->assertSessionHasErrors('digest');

    Queue::assertNothingPushed();
});

<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\SystemSetting;
use App\Services\Oci\Digest;
use App\Services\Registry\OciSettings;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Push-time repository creation: off by default, switched on instance-wide, narrowable per
 * organization — the same ceiling/narrowing relationship `enabled_registry_types` has.
 *
 * The push cases below address the registry by CUSTOM DOMAIN, the mode most of
 * tests/Feature/Oci uses; the last case repeats one of them through Task 1's path
 * addressing, because the setting is decided from `$group->organization` and must not turn
 * out to depend on how the caller found that group.
 */

/**
 * A Basic-auth Authorization header as $server vars, for the same reason
 * basicAuthServerVars() in BlobUploadTest.php is: the raw call() a push needs never reads
 * withHeaders()'s defaults. Named differently from that one and from PathAddressingTest's
 * pathAuth() because a Pest test file's top-level functions are global and would collide.
 *
 * @return array<string, string>
 */
function autoCreateAuth(Group $group, TokenAbility $ability = TokenAbility::Read): array
{
    return ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('x:'.tokenPlainTextFor($group, $ability))];
}

beforeEach(function () {
    Storage::fake('artifacts');

    $this->org = Organization::factory()->create([
        'slug' => 'kunde',
        'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
    ]);
    $this->group = Group::factory()->for($this->org)->create(['slug' => 'intern']);
    Domain::create(['group_id' => $this->group->id, 'hostname' => 'push.test']);

    $this->publish = autoCreateAuth($this->group, TokenAbility::Publish);
    $this->read = autoCreateAuth($this->group);

    $this->bytes = 'ein-layer';
    $this->blobDigest = Digest::of($this->bytes);
});

/**
 * The monolithic blob upload a `docker push` starts with, aimed at the repository name
 * `neu`, which no case below registers up front — so this is the request the setting
 * decides. Domain mode; the path-mode case at the bottom writes its own URL out in full.
 *
 * Everything it needs is passed in rather than read back off `test()`, which static
 * analysis only sees as Pest's proxy and cannot type.
 *
 * @param  array<string, string>  $publish
 * @return TestResponse<Response>
 */
function pushBlobIntoNeu(array $publish, string $bytes): TestResponse
{
    return test()->withServerVariables($publish)
        ->call('POST', 'http://push.test/v2/neu/blobs/uploads/?digest='.Digest::of($bytes), content: $bytes);
}

it('intersects the global setting with the organization rather than coalescing', function (bool $global, ?bool $org, bool $effective) {
    // The truth table. Row two is the one `$org->value ?? $global` gets wrong: an
    // organization may narrow the instance ceiling, never widen past it.
    SystemSetting::current()->update(['oci_auto_create_repositories' => $global]);
    $organization = Organization::factory()->create(['oci_auto_create_repositories' => $org]);

    expect(app(OciSettings::class)->autoCreateEnabledFor($organization))->toBe($effective);
})->with([
    'global off, org inherits' => [false, null, false],
    'global off, org on — may never widen past the ceiling' => [false, true, false],
    'global on, org inherits' => [true, null, true],
    'global on, org off' => [true, false, false],
    'global on, org on' => [true, true, true],
]);

it('falls back to the global setting when there is no organization to narrow it', function () {
    expect(app(OciSettings::class)->autoCreateEnabledFor(null))->toBeFalse();

    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    expect(app(OciSettings::class)->autoCreateEnabledFor(null))->toBeTrue();
});

it('is off after a migration, so a push to an unknown name is still refused', function () {
    expect(SystemSetting::current()->oci_auto_create_repositories)->toBeFalse();

    $before = Package::count();

    $response = pushBlobIntoNeu($this->publish, $this->bytes)
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');

    // Both halves: the status AND that nothing was written behind it.
    expect(Package::count())->toBe($before)
        // The message names the setting. A bare "gibt es nicht" sends the one person who
        // can fix this in a checkbox to the logs instead.
        ->and($response->json('errors.0.message'))->toContain('Repositories beim Push anlegen');
});

it('refuses when the organization narrows a globally enabled setting', function () {
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);
    $this->org->update(['oci_auto_create_repositories' => false]);

    $before = Package::count();

    pushBlobIntoNeu($this->publish, $this->bytes)->assertStatus(404)->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');

    expect(Package::count())->toBe($before);
});

it('refuses even when the organization switches on what the instance has switched off', function () {
    // Row two of the truth table, at the protocol rather than at the service: an
    // organization's own `true` under a globally disabled setting is inert.
    $this->org->update(['oci_auto_create_repositories' => true]);

    $before = Package::count();

    pushBlobIntoNeu($this->publish, $this->bytes)->assertStatus(404)->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');

    expect(Package::count())->toBe($before);
});

it('creates the repository in the addressed organization and registry when allowed', function () {
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    pushBlobIntoNeu($this->publish, $this->bytes)
        ->assertStatus(201)
        ->assertHeader('Docker-Content-Digest', $this->blobDigest);

    $created = Package::where('name', 'neu')->sole();

    expect($created->type)->toBe(PackageType::Docker)
        // Load-bearing and easy to omit: a Docker row that ends up git-sourced is never
        // synced and says so only by staying empty.
        ->and($created->source_mode)->toBe(PackageSourceMode::Publish)
        ->and($created->organization_id)->toBe($this->org->id)
        // Attached to the registry the push addressed, or the very next request 404s.
        ->and($this->group->packages()->whereKey($created->id)->exists())->toBeTrue();
});

it('makes the created repository pullable by the very next request', function () {
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);
    $payload = '{"schemaVersion":2,"mediaType":"application/vnd.oci.image.manifest.v1+json","layers":[]}';
    $manifestDigest = Digest::of($payload);

    pushBlobIntoNeu($this->publish, $this->bytes)->assertStatus(201);

    $this->withServerVariables($this->publish + ['CONTENT_TYPE' => 'application/vnd.oci.image.manifest.v1+json'])
        ->call('PUT', 'http://push.test/v2/neu/manifests/1.0', content: $payload)
        ->assertStatus(201);

    $this->withServerVariables($this->read)
        ->get('http://push.test/v2/neu/manifests/1.0')
        ->assertOk()
        ->assertHeader('Docker-Content-Digest', $manifestDigest);
});

it('never creates a name another organization already holds, even when allowed', function () {
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    // The same name, owned elsewhere. Answering anything but NAME_UNKNOWN would let a
    // publish token discover foreign repository names by response code.
    $foreign = Organization::factory()->create(['enabled_registry_types' => ['docker']]);
    $foreignGroup = Group::factory()->for($foreign)->create();
    $held = Package::factory()->inOrgOf($foreignGroup)->create(['type' => PackageType::Docker, 'name' => 'neu']);
    $foreignGroup->packages()->attach($held);

    $before = Package::count();

    pushBlobIntoNeu($this->publish, $this->bytes)->assertStatus(404)->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');

    expect(Package::count())->toBe($before);
});

it('creates the repository through the path address too', function () {
    // Task 1's second addressing mode. The setting hangs off the resolved group's
    // organization, so both modes have to reach the same decision.
    SystemSetting::current()->update(['oci_auto_create_repositories' => true]);

    $host = rtrim((string) config('app.url'), '/');

    $this->withServerVariables($this->publish)
        ->call('POST', "{$host}/v2/kunde/intern/neu/blobs/uploads/?digest={$this->blobDigest}", content: $this->bytes)
        ->assertStatus(201)
        // In the caller's own address space, or the client follows the Location into a 404.
        ->assertHeader('Location', "/v2/kunde/intern/neu/blobs/{$this->blobDigest}");

    expect(Package::where('name', 'neu')->sole()->organization_id)->toBe($this->org->id);
});

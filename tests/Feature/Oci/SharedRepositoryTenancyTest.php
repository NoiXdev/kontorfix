<?php

use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Domain;
use App\Models\Group;
use App\Models\OciBlob;
use App\Models\OciManifest;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Oci\Digest;
use Illuminate\Support\Facades\Storage;

/**
 * Two Criticals from the whole-branch review, both closed the same way NpmController and
 * PypiController already close them for their own ecosystems: a shared package hands out
 * reads, never writes, and never leaks content that belongs to a DIFFERENT repository of
 * the owning (operator) organization just because that other repository happens to sit in
 * the same organization's blob-dedup bucket.
 *
 * Kept as its own file, its own auth-header helper, rather than reusing
 * BlobUploadTest.php's/BlobDownloadTest.php's — every *Test.php file in this project runs
 * in one PHP process (serial suite, never --parallel), so two files declaring the same
 * top-level function name would be a fatal redeclaration, not a merge.
 *
 * @return array<string, string>
 */
function tenancyAuthServerVars(Group $group, TokenAbility $ability): array
{
    $plain = tokenPlainTextFor($group, $ability);

    return ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('x:'.$plain)];
}

beforeEach(function () {
    Storage::fake('artifacts');

    $this->operatorOrg = Organization::factory()->create([
        'is_operator' => true,
        'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
    ]);
    $this->operatorGroup = Group::factory()->for($this->operatorOrg)->create();
    Domain::create(['group_id' => $this->operatorGroup->id, 'hostname' => 'operator.test']);

    $this->customerOrg = Organization::factory()->create([
        'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
    ]);
    $this->customerGroup = Group::factory()->for($this->customerOrg)->create();
    Domain::create(['group_id' => $this->customerGroup->id, 'hostname' => 'customer.test']);

    // The shared repository: owned by the operator, assigned into the customer's own
    // registry — the shape every "sharing" fixture across the suite uses (see
    // tests/Feature/Registry/SharedPackageResolutionTest.php's sharedPackageIn()).
    $this->shared = Package::factory()->for($this->operatorOrg)->create([
        'type' => PackageType::Docker, 'name' => 'shared-base', 'shared' => true,
    ]);
    $this->operatorGroup->packages()->attach($this->shared);
    $this->customerGroup->packages()->attach($this->shared);

    $this->customerPublish = tenancyAuthServerVars($this->customerGroup, TokenAbility::Publish);
    $this->customerRead = tenancyAuthServerVars($this->customerGroup, TokenAbility::Read);
});

// --- Critical 2: sharing hands out reads, never writes ---

it('refuses a customer publish token pushing a blob into a shared repository', function () {
    $bytes = random_bytes(64);
    $digest = Digest::of($bytes);

    $this->withServerVariables($this->customerPublish)
        ->call('POST', "http://customer.test/v2/shared-base/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');

    // Not merely refused at the HTTP layer: nothing was actually written into the
    // operator's blob store for this digest.
    expect(OciBlob::where('organization_id', $this->operatorOrg->id)->where('digest', $digest)->exists())->toBeFalse();
});

it('refuses a customer publish token writing a manifest into a shared repository', function () {
    $payload = json_encode(['schemaVersion' => 2, 'mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'layers' => []]);

    $this->withServerVariables($this->customerPublish)
        ->call('PUT', 'http://customer.test/v2/shared-base/manifests/latest', content: $payload)
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');

    expect(OciManifest::where('package_id', $this->shared->id)->exists())->toBeFalse();
});

it('refuses a customer publish token deleting a manifest from a shared repository', function () {
    // The operator pushes a real manifest first, so there is something a leak could
    // actually delete.
    $payload = json_encode(['schemaVersion' => 2, 'mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'layers' => []]);
    $operatorPublish = tenancyAuthServerVars($this->operatorGroup, TokenAbility::Publish);
    $digest = Digest::of($payload);
    $this->withServerVariables($operatorPublish)
        ->call('PUT', "http://operator.test/v2/shared-base/manifests/{$digest}", content: $payload)
        ->assertStatus(201);

    $this->withServerVariables($this->customerPublish)
        ->call('DELETE', "http://customer.test/v2/shared-base/manifests/{$digest}")
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');

    expect(OciManifest::where('package_id', $this->shared->id)->where('digest', $digest)->exists())->toBeTrue();
});

it('refuses a publish token whose OWN (non-shared) assignment has lapsed', function () {
    // The other half of the same bug: ociWritableRepository() used $group->packages(),
    // which carries no expiry predicate at all — an assignment whose available_until has
    // passed stayed writable forever. Own-organization package this time, not a shared
    // one, to isolate the expiry predicate from the shared-package predicate.
    $own = Package::factory()->for($this->customerOrg)->create(['type' => PackageType::Docker, 'name' => 'own-app']);
    $this->customerGroup->packages()->attach($own, ['available_until' => now()->subDay()]);

    $bytes = random_bytes(32);
    $digest = Digest::of($bytes);

    $this->withServerVariables($this->customerPublish)
        ->call('POST', "http://customer.test/v2/own-app/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');
});

it('still lets the customer publish token write into its OWN unexpired repository', function () {
    // The fix must not overcorrect into refusing every write: own-organization,
    // non-shared, unexpired stays writable exactly as before.
    $own = Package::factory()->for($this->customerOrg)->create(['type' => PackageType::Docker, 'name' => 'own-app']);
    $this->customerGroup->packages()->attach($own);

    $bytes = random_bytes(32);
    $digest = Digest::of($bytes);

    $this->withServerVariables($this->customerPublish)
        ->call('POST', "http://customer.test/v2/own-app/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(201);
});

// --- Critical 3: a read token on a shared repository must not reach a blob belonging to
// an unshared repository of the owning organization ---

it('does not let a shared-repository read token pull a blob from an unshared sibling repository', function () {
    // A SECOND Docker repository, owned by the SAME operator organization, never shared
    // and never assigned to the customer's registry — the private repository Critical 3
    // is about. Its blob sits in the same organization-scoped dedup bucket as the shared
    // repository's blobs (spec §2: blobs deduplicate per organization, never per
    // repository), which is exactly what used to make it reachable.
    $private = Package::factory()->for($this->operatorOrg)->create(['type' => PackageType::Docker, 'name' => 'private-app']);
    $this->operatorGroup->packages()->attach($private);

    $bytes = random_bytes(128);
    $digest = Digest::of($bytes);
    $operatorPublish = tenancyAuthServerVars($this->operatorGroup, TokenAbility::Publish);
    $this->withServerVariables($operatorPublish)
        ->call('POST', "http://operator.test/v2/private-app/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(201);
    // Reference it from a manifest too, so this is not merely testing the "not yet
    // manifested" grace window (see BlobController::show()'s own comment on why the
    // shared-package check only applies to shared packages) — the digest genuinely
    // belongs to private-app's own content, manifested there, never anywhere the
    // customer's registry serves.
    $manifest = json_encode(['schemaVersion' => 2, 'mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'layers' => [['mediaType' => 'application/vnd.oci.image.layer.v1.tar', 'digest' => $digest, 'size' => strlen($bytes)]]]);
    $this->withServerVariables($operatorPublish)
        ->call('PUT', 'http://operator.test/v2/private-app/manifests/latest', content: $manifest)
        ->assertStatus(201);

    // The customer's read token can only ever reach `shared-base` (private-app was never
    // assigned to its registry) — but the digest is requested through shared-base's own
    // name, so this asks whether the ORGANIZATION-scoped blob bucket leaks across
    // repositories, not whether the customer can guess private-app's name.
    $this->withServerVariables($this->customerRead)
        ->call('GET', "http://customer.test/v2/shared-base/blobs/{$digest}")
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'BLOB_UNKNOWN');

    $this->withServerVariables($this->customerRead)
        ->call('HEAD', "http://customer.test/v2/shared-base/blobs/{$digest}")
        ->assertStatus(404);
});

it('still lets a shared-repository read token pull a blob the shared repository itself actually references', function () {
    // The positive case the fix must not break: a blob genuinely pushed to, and
    // manifested by, the SHARED repository itself must still be readable through it.
    $bytes = random_bytes(96);
    $digest = Digest::of($bytes);
    $operatorPublish = tenancyAuthServerVars($this->operatorGroup, TokenAbility::Publish);
    $this->withServerVariables($operatorPublish)
        ->call('POST', "http://operator.test/v2/shared-base/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(201);
    $manifest = json_encode(['schemaVersion' => 2, 'mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'layers' => [['mediaType' => 'application/vnd.oci.image.layer.v1.tar', 'digest' => $digest, 'size' => strlen($bytes)]]]);
    $this->withServerVariables($operatorPublish)
        ->call('PUT', 'http://operator.test/v2/shared-base/manifests/latest', content: $manifest)
        ->assertStatus(201);

    $this->withServerVariables($this->customerRead)
        ->call('GET', "http://customer.test/v2/shared-base/blobs/{$digest}")
        ->assertOk()
        ->assertHeader('Docker-Content-Digest', $digest);
});

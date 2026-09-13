<?php

// `sync_error` is member-tier readable: GET /api/v1/status/packages and
// GET /api/v1/packages both sit behind plain `api.auth` WITHOUT `operator`, and both hand
// the column back verbatim. In the very same responses `repository_url` is passed through
// CredentialUrl::redact() with a comment saying member-tier readers must not see the
// credential — so the project already draws this boundary, and the mirror importer's
// failure messages walked straight across it.
//
// A dist URL carrying Basic-auth userinfo is a documented, supported shape for a private
// Satis/mirror feed, and UrlSafety validates scheme, host and address but never userinfo.
// Any ordinary failure — a 404 at the source, a size cap, a checksum mismatch, a truncated
// transfer — then wrote the credential into the column.

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Exceptions\MirrorSyncFailed;
use App\Models\Group;
use App\Models\MirrorSource;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Mirror\MirrorImporter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const CREDENTIALED_DIST = 'https://mirror-user:s3cr3t-pat@repo.test/dist/acme-demo-1.0.0.zip';

/** @return array{0: MirrorSource, 1: Package} */
function mirrorImportFixture(): array
{
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create();
    $source = MirrorSource::factory()->create([
        'organization_id' => $group->organization_id,
        'url' => 'https://repo.test',
    ]);
    $package = Package::factory()->inOrgOf($group)->create([
        'organization_id' => $source->organization_id,
        'name' => 'acme/demo',
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/demo',
        'repository_url' => null,
    ]);

    return [$source, $package];
}

/** Every failure shape fetchArtifact() can take, each one named by the message it writes. */
dataset('artifact failure shapes', [
    'source refuses the artifact' => [fn () => Http::fake([CREDENTIALED_DIST => Http::response('', 500)])],
    'source does not have it' => [fn () => Http::fake([CREDENTIALED_DIST => Http::response('', 404)])],
    'artifact exceeds the size cap' => [fn () => Http::fake([CREDENTIALED_DIST => Http::response(str_repeat('x', 4096), 200)])],
    'checksum does not match' => [fn () => Http::fake([CREDENTIALED_DIST => Http::response('zip-bytes', 200)])],
]);

it('never writes a dist url credential into a sync failure message', function (Closure $fake) {
    [$source] = mirrorImportFixture();
    $fake();

    $importer = app(MirrorImporter::class);

    try {
        $importer->fetchArtifact(
            $source,
            CREDENTIALED_DIST,
            'acme/demo/1.0.0.zip',
            maxBytes: 1024,
            sha256: str_repeat('a', 64),
        );
        $thrown = null;
    } catch (MirrorSyncFailed $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->not->toContain('s3cr3t-pat')
        ->and($thrown->getMessage())->not->toContain('mirror-user')
        // …and still names the artifact, because an operator has to be able to tell which
        // one failed. Redaction that removed the whole URL would trade one defect for another.
        ->and($thrown->getMessage())->toContain('repo.test/dist/acme-demo-1.0.0.zip')
        ->and($thrown->getMessage())->toContain('***');
})->with('artifact failure shapes');

it('keeps the credential out of the column the api hands to members', function () {
    [$source, $package] = mirrorImportFixture();
    Http::fake([CREDENTIALED_DIST => Http::response('', 500)]);

    try {
        app(MirrorImporter::class)->fetchArtifact($source, CREDENTIALED_DIST, 'acme/demo/1.0.0.zip', maxBytes: 1024);
    } catch (MirrorSyncFailed $e) {
        $package->forceFill(['sync_error' => $e->getMessage()])->save();
    }

    expect($package->fresh()->sync_error)->not->toContain('s3cr3t-pat');
});

<?php

namespace App\Services\Mirror;

use App\Exceptions\MirrorSyncFailed;
use App\Exceptions\UpstreamException;
use App\Models\MirrorSource;
use App\Models\Package;
use App\Services\Upstream\UpstreamClient;
use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Storage;
use UnexpectedValueException;

/**
 * Imports a Composer package's versions from a mirrored foreign registry's Composer-v2
 * metadata (`/p2/{name}.json`) — the mirror counterpart of what GitSourceImporter's
 * importManifestVersion() does for a git-sourced Composer package, except the dist zip is
 * fetched from the mirror rather than built lazily from a clone, so a PackageVersion row is
 * only ever created once its dist has actually landed on the artifacts disk (row-only-
 * after-artifact — see MirrorImporter::fetchArtifact()).
 *
 * A version already imported (same normalized version, existing dist file whose size
 * matches the stored dist_size) is never re-downloaded — this is what makes a repeated
 * sync idempotent and cheap. A version dropped from the upstream feed is left alone: this
 * class only ever adds/updates rows, never deletes one.
 */
class ComposerMirrorImport
{
    public function __construct(
        private readonly MirrorImporter $importer,
        private readonly UpstreamClient $client,
    ) {}

    public function import(Package $package, MirrorSource $source): void
    {
        $name = (string) $package->mirror_name;

        try {
            $payload = $this->client->getJson($source, "/p2/{$name}.json");
        } catch (UpstreamException $e) {
            throw MirrorSyncFailed::because("Composer-v2-Metadaten für „{$name}“ konnten nicht geladen werden: {$e->getMessage()}");
        }

        if ($payload === null) {
            throw MirrorSyncFailed::because("Paket „{$name}“ bei der Quelle nicht gefunden (Composer-v2-Metadaten erforderlich).");
        }

        $minified = $payload['packages'][$name] ?? [];
        $versions = MetadataMinifier::expand(is_array($minified) ? $minified : []);

        $parser = new VersionParser;
        $maxBytes = (int) config('kontorfix.composer_max_dist_bytes', 100 * 1024 * 1024);
        $disk = Storage::disk('artifacts');

        foreach ($versions as $version) {
            if (! is_array($version)) {
                continue;
            }

            $tag = $version['version'] ?? null;
            if (! is_string($tag) || $tag === '') {
                continue;
            }

            try {
                $normalized = $parser->normalize($tag);
            } catch (UnexpectedValueException) {
                continue; // not a version tag — skip silently, mirrors GitSourceImporter's tolerance
            }

            $dist = $version['dist'] ?? null;
            if (! is_array($dist) || ! isset($dist['url']) || ! is_string($dist['url'])) {
                continue; // source-only version — nothing to fetch, no row without an artifact
            }

            $distPath = 'dists/'.$package->id.'/'.sha1($normalized).'.zip';

            $expectedSha1 = null;
            $shasum = $dist['shasum'] ?? null;
            if (is_string($shasum) && $shasum !== '') {
                $expectedSha1 = $shasum;
            }

            $existing = $package->versions()->where('version', $normalized)->first();
            if ($existing !== null
                && $existing->dist_path !== null
                && $existing->dist_size !== null
                && $disk->exists($existing->dist_path)
                && $disk->size($existing->dist_path) === $existing->dist_size
            ) {
                continue; // already imported and the artifact is intact — no re-download
            }

            [$size] = $this->importer->fetchArtifact($source, $dist['url'], $distPath, $maxBytes, null, $expectedSha1);

            $package->versions()->updateOrCreate(
                ['version' => $normalized],
                [
                    'version_pretty' => $tag,
                    'source_reference' => null,
                    'metadata' => $version,
                    'released_at' => $version['time'] ?? null,
                    'dist_path' => $distPath,
                    'dist_size' => $size,
                    // Only ever populated when the upstream metadata itself carried a
                    // shasum — fetchArtifact() already verified it matches the downloaded
                    // bytes, so this is a faithful echo of the upstream value, not the
                    // computed hash restated for a version that declared none.
                    'dist_shasum' => $expectedSha1,
                ],
            );
        }
    }
}

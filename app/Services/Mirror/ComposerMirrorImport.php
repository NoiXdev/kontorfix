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

    /**
     * Fetches and parses this mirror's Composer-v2 metadata (`/p2/{name}.json`) for $name —
     * the HTTP fetch, JSON parsing AND "is this entry a real, importable version" decision
     * all live here once. import() below iterates exactly the `entries` this returns rather
     * than re-deriving that decision from the raw feed a second time, so its row set can
     * never drift from what App\Services\Mirror\MirrorProbe (a cheap, read-only preview
     * before a package is even created) reports as `versions`.
     *
     * `entries` carries the parsed shape import() actually needs per version (the tag as
     * Composer wrote it, its normalized form, and the full raw metadata for dist URL/shasum/
     * released_at/…) — a preview call keeps only `versions` (the tags) and throws the rest
     * away; import() is what actually fetches the dists.
     *
     * @return array{name: string, description: string|null, versions: list<string>, entries: list<array{tag: string, normalized: string, raw: array<string, mixed>}>}
     */
    public function fetchMetadata(MirrorSource $source, string $name): array
    {
        try {
            $payload = $this->client->getJson($source, "/p2/{$name}.json");
        } catch (UpstreamException $e) {
            // $e->status() is a language-neutral fact (an HTTP status code, or null for a
            // transport-level refusal); $e->getMessage() is English prose and MUST NOT be
            // spliced in here — this message is shown to operators as Package::sync_error
            // (or, from the probe, as the create form's error banner) and is otherwise
            // entirely German.
            $suffix = $e->status() !== null ? " (HTTP {$e->status()})" : '';
            throw MirrorSyncFailed::because("Composer-v2-Metadaten für „{$name}“ konnten nicht geladen werden{$suffix}.");
        }

        if ($payload === null) {
            throw MirrorSyncFailed::because("Paket „{$name}“ bei der Quelle nicht gefunden (Composer-v2-Metadaten erforderlich).");
        }

        $minified = $payload['packages'][$name] ?? [];
        $expanded = MetadataMinifier::expand(is_array($minified) ? $minified : []);

        $parser = new VersionParser;
        $entries = [];
        $description = null;
        foreach ($expanded as $version) {
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
            $entries[] = ['tag' => $tag, 'normalized' => $normalized, 'raw' => $version];
            if ($description === null && is_string($version['description'] ?? null)) {
                $description = $version['description'];
            }
        }

        return [
            'name' => $name,
            'description' => $description,
            'versions' => array_map(static fn (array $e): string => $e['tag'], $entries),
            'entries' => $entries,
        ];
    }

    public function import(Package $package, MirrorSource $source): void
    {
        $name = (string) $package->mirror_name;
        $entries = $this->fetchMetadata($source, $name)['entries'];

        $maxBytes = (int) config('kontorfix.composer_max_dist_bytes', 100 * 1024 * 1024);
        $disk = Storage::disk('artifacts');

        foreach ($entries as $entry) {
            $tag = $entry['tag'];
            $normalized = $entry['normalized'];
            $version = $entry['raw'];

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

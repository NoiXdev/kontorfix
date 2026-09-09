<?php

namespace App\Services\Mirror;

use App\Exceptions\MirrorSyncFailed;
use App\Exceptions\UpstreamException;
use App\Models\MirrorSource;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\Npm\NpmPublishService;
use App\Services\Upstream\UpstreamClient;
use App\Services\Vcs\ReadmeRenderer;
use Composer\Semver\VersionParser;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Imports an npm package's versions from a mirrored foreign registry's packument
 * (`GET /{name}`, a scoped name percent-encoded the way the npm client itself sends it —
 * "@vendor/pkg" becomes "@vendor%2Fpkg") — the mirror counterpart of what
 * NpmPublishService::publish() does for an uploaded tarball, except every tarball here is
 * fetched from the mirror rather than uploaded, so a PackageVersion row is only ever
 * created once its tarball has actually landed on the artifacts disk (row-only-after-
 * artifact — see MirrorImporter::fetchArtifact()).
 *
 * A version already imported (existing row whose declared checksum — dist.shasum, or
 * dist.integrity when shasum is absent — matches what the feed still declares, artifact
 * intact on disk) is never re-downloaded. A version dropped from the upstream feed is left
 * alone: this class only ever adds/updates rows, never deletes one.
 */
class NpmMirrorImport
{
    public function __construct(
        private readonly MirrorImporter $importer,
        private readonly UpstreamClient $client,
    ) {}

    /**
     * Fetches and parses this mirror's npm packument (`GET /{name}`, percent-encoded the way
     * the npm client itself sends a scoped name) for $name — the HTTP fetch, JSON parsing AND
     * "is this entry a real, importable version" decision all live here once. import() below
     * iterates exactly the `entries` this returns rather than re-deriving that decision from
     * the raw packument a second time, so its row set can never drift from what
     * App\Services\Mirror\MirrorProbe (a cheap, read-only preview before a package is even
     * created) reports as `versions`.
     *
     * `entries` carries the parsed per-version shape import() needs (the version string and
     * its full raw metadata — dist URL, shasum, integrity, …); `raw` carries the whole
     * packument for the two things import() needs beyond individual versions (dist-tags,
     * readme). A preview call keeps only `versions` (the tags) and throws the rest away;
     * import() is what actually fetches the tarballs.
     *
     * @return array{name: string, description: string|null, versions: list<string>, entries: list<array{tag: string, raw: array<string, mixed>}>, raw: array<string, mixed>}
     */
    public function fetchMetadata(MirrorSource $source, string $name): array
    {
        $encodedName = str_replace('/', '%2F', $name);

        try {
            $packument = $this->client->getJson($source, "/{$encodedName}");
        } catch (UpstreamException $e) {
            // $e->status() is a language-neutral fact (an HTTP status code, or null for a
            // transport-level refusal); $e->getMessage() is English prose and MUST NOT be
            // spliced in here — this message is shown to operators as Package::sync_error
            // (or, from the probe, as the create form's error banner) and is otherwise
            // entirely German.
            $suffix = $e->status() !== null ? " (HTTP {$e->status()})" : '';
            throw MirrorSyncFailed::because("npm-Packument für „{$name}“ konnte nicht geladen werden{$suffix}.");
        }

        if ($packument === null) {
            throw MirrorSyncFailed::because("Paket „{$name}“ bei der Quelle nicht gefunden (npm-Packument erforderlich).");
        }

        $versions = is_array($packument['versions'] ?? null) ? $packument['versions'] : [];
        $parser = new VersionParser;
        $entries = [];
        foreach ($versions as $tag => $version) {
            if (! is_array($version)) {
                continue; // malformed entry — mirrors import()'s own tolerance
            }
            $versionString = (string) $tag;
            if ($versionString === '') {
                continue;
            }
            try {
                $parser->normalize($versionString);
            } catch (UnexpectedValueException) {
                continue; // not a version tag — skip silently, mirrors ComposerMirrorImport's tolerance
            }
            $entries[] = ['tag' => $versionString, 'raw' => $version];
        }

        $discoveredName = is_string($packument['name'] ?? null) && $packument['name'] !== '' ? $packument['name'] : $name;
        $description = is_string($packument['description'] ?? null) ? $packument['description'] : null;

        return [
            'name' => $discoveredName,
            'description' => $description,
            'versions' => array_map(static fn (array $e): string => $e['tag'], $entries),
            'entries' => $entries,
            'raw' => $packument,
        ];
    }

    public function import(Package $package, MirrorSource $source): void
    {
        $name = (string) $package->mirror_name;
        $metadata = $this->fetchMetadata($source, $name);
        $packument = $metadata['raw'];

        $times = is_array($packument['time'] ?? null) ? $packument['time'] : [];
        $maxBytes = (int) config('kontorfix.npm_max_tarball_bytes', 100 * 1024 * 1024);
        $disk = Storage::disk('artifacts');
        $unscoped = NpmPublishService::unscopedName($package->name);

        foreach ($metadata['entries'] as $entry) {
            $versionString = $entry['tag'];
            $version = $entry['raw'];

            $dist = $version['dist'] ?? null;
            if (! is_array($dist) || ! isset($dist['tarball']) || ! is_string($dist['tarball']) || $dist['tarball'] === '') {
                continue; // nothing to fetch — no row without an artifact
            }

            $shasum = is_string($dist['shasum'] ?? null) && $dist['shasum'] !== '' ? $dist['shasum'] : null;
            $integrity = is_string($dist['integrity'] ?? null) && $dist['integrity'] !== '' ? $dist['integrity'] : null;

            $file = "{$unscoped}-{$versionString}.tgz";
            $distPath = "tarballs/{$package->id}/{$file}";

            $existing = $package->versions()->where('version', $versionString)->first();
            if ($existing !== null && $this->isUpToDate($existing, $disk, $shasum, $integrity)) {
                continue; // already imported and the artifact is intact — no re-download
            }

            // fetchArtifact() only ever verifies sha1/sha256 in-stream, before it atomically
            // moves the bytes into their final path — so a declared shasum is passed straight
            // through and gates that move exactly like ComposerMirrorImport's dist does.
            // dist.integrity (sha512) is the stronger, preferred check, but fetchArtifact
            // cannot perform it; verifying it only *after* fetchArtifact's own move would let
            // a corrupt re-fetch overwrite an already-verified artifact from a previous sync
            // before the mismatch is even detected (same $distPath every sync, by version).
            // So whenever integrity is declared, fetchArtifact is pointed at a staging path
            // instead of $distPath, verifyIntegrity() reads *that*, and only a pass moves it
            // into $distPath — a previously good artifact is never touched by a fetch that
            // ultimately fails.
            $writePath = $integrity !== null ? $this->stagingPath($distPath) : $distPath;

            [$size] = $this->importer->fetchArtifact($source, $dist['tarball'], $writePath, $maxBytes, null, $shasum);

            if ($integrity !== null) {
                $this->verifyIntegrity($disk, $writePath, $integrity, $dist['tarball']);
                $disk->move($writePath, $distPath);
            }

            $package->versions()->updateOrCreate(
                ['version' => $versionString],
                [
                    'version_pretty' => $versionString,
                    'source_reference' => null,
                    'metadata' => $version,
                    'released_at' => $times[$versionString] ?? null,
                    'dist_path' => $distPath,
                    'dist_size' => $size,
                    // Only ever populated when the upstream metadata itself carried the
                    // value — fetchArtifact()/verifyIntegrity() already verified it matches
                    // the downloaded bytes, so this is a faithful echo of the upstream
                    // value, not a computed hash restated for a version that declared none.
                    'dist_shasum' => $shasum,
                    'dist_integrity' => $integrity,
                    'dist_tarball_name' => $file,
                ],
            );
        }

        $package->update([
            'dist_tags' => NpmPublishService::mergeDistTags($package->dist_tags, $packument['dist-tags'] ?? null),
        ]);

        $this->syncReadme($package, $packument);
    }

    /**
     * A sibling path fetchArtifact() can write and verifyIntegrity() can delete freely,
     * distinct from $finalPath so a failed integrity check never touches whatever is
     * already sitting at $finalPath from a previous, already-verified sync.
     */
    private function stagingPath(string $finalPath): string
    {
        return dirname($finalPath).'/.'.basename($finalPath).'.'.Str::random(8).'.integrity-check';
    }

    /** Whether a locally stored version still matches what the feed currently declares for it. */
    private function isUpToDate(PackageVersion $existing, Filesystem $disk, ?string $shasum, ?string $integrity): bool
    {
        if ($existing->dist_path === null || ! $disk->exists($existing->dist_path)) {
            return false;
        }

        if ($shasum !== null) {
            return $existing->dist_shasum === $shasum;
        }
        if ($integrity !== null) {
            return $existing->dist_integrity === $integrity;
        }

        return $existing->dist_shasum === null && $existing->dist_integrity === null;
    }

    /**
     * Verifies dist.integrity (npm's `sha512-<base64>` SRI format) against the bytes just
     * written to $path. fetchArtifact() cannot do this itself — it only ever computes
     * sha1/sha256 — so this reads the artifact back off the artifacts disk and hashes it
     * again. A mismatch (or an integrity value in a format this importer does not support)
     * removes the artifact — nothing may be left behind for a version that fails
     * verification — and throws MirrorSyncFailed, so the caller's row-only-after-artifact
     * rule leaves no PackageVersion for it either.
     */
    private function verifyIntegrity(Filesystem $disk, string $path, string $integrity, string $url): void
    {
        if (! str_starts_with($integrity, 'sha512-')) {
            $disk->delete($path);
            throw MirrorSyncFailed::because("Nicht unterstütztes Integritätsformat des Artefakts: {$url}");
        }

        $expected = base64_decode(substr($integrity, strlen('sha512-')), true);

        $context = hash_init('sha512');
        $stream = $disk->readStream($path);
        if (is_resource($stream)) {
            while (! feof($stream)) {
                $chunk = fread($stream, 262144);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                hash_update($context, $chunk);
            }
            fclose($stream);
        }
        $actual = hash_final($context, true);

        if ($expected === false || ! hash_equals($expected, $actual)) {
            $disk->delete($path);
            throw MirrorSyncFailed::because("Prüfsumme (integrity) des Artefakts stimmt nicht überein: {$url}");
        }
    }

    /**
     * A README is a nice-to-have — as in SyncPackage::syncReadme(), it must never be able to
     * fail an import, so a render failure is logged and swallowed. Unlike that method, an
     * absent `readme` key is not itself an answer here: many registries omit it from the
     * packument entirely, independent of whether a README actually exists upstream, so this
     * only ever writes readme_html when the feed actually declares one and it renders —
     * anything else leaves the previously stored value alone.
     *
     * @param  array<string, mixed>  $packument
     */
    private function syncReadme(Package $package, array $packument): void
    {
        $readme = $packument['readme'] ?? null;
        if (! is_string($readme) || trim($readme) === '') {
            return;
        }

        try {
            $html = ReadmeRenderer::render($readme, 'README.md');
        } catch (Throwable $e) {
            Log::warning('npm mirror readme render failed', [
                'package_id' => $package->id,
                'reason' => $e->getMessage(),
            ]);

            return; // Keep the previous value rather than blanking on a parse error.
        }

        $package->update(['readme_html' => $html !== '' ? $html : null]);
    }
}

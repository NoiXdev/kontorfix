<?php

namespace App\Services\Mirror;

use App\Exceptions\MirrorSyncFailed;
use App\Exceptions\UpstreamException;
use App\Models\MirrorSource;
use App\Models\Package;
use App\Services\Python\PythonName;
use App\Services\Upstream\UpstreamClient;
use Illuminate\Support\Facades\Storage;

/**
 * Imports a Python package's distribution files from a mirrored foreign registry's PEP 691
 * project detail feed (`GET /simple/{normalized}/` with
 * `Accept: application/vnd.pypi.simple.v1+json`) — the mirror counterpart of what
 * PythonPublishService::publish() does for a twine upload, except every distribution here is
 * fetched from the mirror rather than uploaded, so a PythonDist row is only ever created once
 * its file has actually landed on the artifacts disk (row-only-after-artifact — see
 * MirrorImporter::fetchArtifact()).
 *
 * PyPI is file-centric — a "version" is only ever a label parsed back out of a filename, and
 * distinct files (an sdist plus one or more wheels) can carry the same version — so this
 * mirrors PythonPublishService and keys on `filename`, not `version`, exactly like the
 * unique index on python_dists. A file already imported (existing row for that filename whose
 * declared sha256 still matches what the feed declares, artifact intact on disk) is never
 * re-downloaded. A file dropped from the upstream feed is left alone: this class only ever
 * adds rows, never deletes one.
 *
 * No README handling: PyPI's PEP 691 feed carries no README, and v1 of this importer adds
 * none of its own (a deliberate scope decision, not an oversight).
 */
class PythonMirrorImport
{
    private const JSON_ACCEPT = 'application/vnd.pypi.simple.v1+json';

    /** Mirrors PythonPublishService's filename validation — a safe, traversal-free shape. */
    private const FILENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._+-]*\.(whl|tar\.gz|zip)$/';

    public function __construct(
        private readonly MirrorImporter $importer,
        private readonly UpstreamClient $client,
    ) {}

    public function import(Package $package, MirrorSource $source): void
    {
        $name = (string) $package->mirror_name;
        $normalized = PythonName::normalize($name);

        try {
            $payload = $this->client->getJson($source, "/simple/{$normalized}/", ['Accept' => self::JSON_ACCEPT]);
        } catch (UpstreamException $e) {
            // $e->status() is a language-neutral fact (an HTTP status code, or null for a
            // transport-level refusal); $e->getMessage() is English prose and MUST NOT be
            // spliced in here — this message is shown to operators as Package::sync_error
            // and is otherwise entirely German.
            $suffix = $e->status() !== null ? " (HTTP {$e->status()})" : '';
            throw MirrorSyncFailed::because("PyPI-Metadaten (PEP 691) für „{$name}“ konnten nicht geladen werden{$suffix}.");
        }

        // getJson() also answers null for a 2xx response whose body isn't valid JSON — e.g.
        // an upstream serving an HTML error page with a 200 — since json_decode() fails
        // silently rather than raising (see UpstreamClient::getJson). That is
        // indistinguishable here from "no PEP 691 feed for this project", which is exactly
        // what it means from this importer's point of view either way: the feed it needs
        // never arrived usably, so it is reported the same way as a 404.
        if ($payload === null) {
            throw MirrorSyncFailed::because("Paket „{$name}“ bei der Quelle nicht gefunden (PEP 691 erforderlich).");
        }

        $files = is_array($payload['files'] ?? null) ? $payload['files'] : [];
        $maxBytes = (int) config('kontorfix.python_max_dist_bytes', 200 * 1024 * 1024);
        $disk = Storage::disk('artifacts');

        foreach ($files as $file) {
            if (! is_array($file)) {
                continue; // malformed entry — skip silently, mirrors ComposerMirrorImport's tolerance
            }

            $filename = $file['filename'] ?? null;
            $url = $file['url'] ?? null;
            if (! is_string($filename) || $filename === '' || ! is_string($url) || $url === '') {
                continue; // nothing to fetch, or no safe name to key the row on
            }
            if (! preg_match(self::FILENAME_PATTERN, $filename) || str_contains($filename, '..')) {
                continue; // unsafe or unrecognised filename shape — never used to build a disk path
            }

            $filetype = str_ends_with($filename, '.whl') ? 'bdist_wheel' : 'sdist';
            $version = $this->versionFromFilename($filename, $filetype);
            if ($version === null) {
                continue; // could not recover a version from the filename — nothing to record it under
            }

            $hashes = $file['hashes'] ?? null;
            $declaredSha256 = is_array($hashes) && is_string($hashes['sha256'] ?? null) && $hashes['sha256'] !== ''
                ? strtolower($hashes['sha256'])
                : null;

            $path = "pypi/{$package->id}/{$filename}";

            $existing = $package->pythonDists()->where('filename', $filename)->first();
            if ($existing !== null
                && $disk->exists($existing->path)
                // PyPI filenames are immutable — a project cannot re-upload the same
                // filename with different bytes. So when the feed still declares the
                // hash this row was imported with, the artifact is known-intact and
                // there is nothing to re-verify; when the feed declares no hash at all,
                // there is no signal that anything changed either, and re-fetching a
                // file whose name PyPI guarantees is fixed would just waste bandwidth.
                && ($declaredSha256 === null || $existing->sha256 === $declaredSha256)
            ) {
                continue; // already imported and the artifact is intact — no re-download
            }

            $requiresPython = is_string($file['requires-python'] ?? null) && $file['requires-python'] !== ''
                ? $file['requires-python']
                : null;

            $uploadTime = is_string($file['upload-time'] ?? null) && $file['upload-time'] !== ''
                ? $file['upload-time']
                : null;

            [$size, , $gotSha256] = $this->importer->fetchArtifact($source, $url, $path, $maxBytes, $declaredSha256);

            $package->pythonDists()->updateOrCreate(
                ['filename' => $filename],
                [
                    'version' => $version,
                    'filetype' => $filetype,
                    'path' => $path,
                    // Persisted even when the feed declared no hash to verify against —
                    // it is the sha256 fetchArtifact() itself computed while streaming the
                    // file to disk, not an unverified echo of upstream-declared data.
                    'sha256' => $declaredSha256 ?? $gotSha256,
                    'size' => $size,
                    'requires_python' => $requiresPython,
                    'uploaded_at' => $uploadTime ?? now(),
                ],
            );
        }
    }

    /**
     * Recovers the release version from a distribution filename, the only place PEP 691
     * carries it. A wheel's filename is the fixed-shape
     * `{distribution}-{version}(-{build})?-{python tag}-{abi tag}-{platform tag}.whl` — the
     * wheel filename-escaping rules (PEP 427) replace any `-` inside the distribution name
     * with `_`, so the version is always the second `-`-separated segment regardless of what
     * the project is called. An sdist's filename is just `{distribution}-{version}.tar.gz`
     * (or `.zip`), escaped the same way — so the version is everything after the first `-`.
     */
    private function versionFromFilename(string $filename, string $filetype): ?string
    {
        if ($filetype === 'bdist_wheel') {
            $stem = substr($filename, 0, -strlen('.whl'));
            $parts = explode('-', $stem);

            return isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
        }

        $suffix = str_ends_with($filename, '.tar.gz') ? '.tar.gz' : '.zip';
        $stem = substr($filename, 0, -strlen($suffix));

        $pos = strpos($stem, '-');
        if ($pos === false || $pos === strlen($stem) - 1) {
            return null;
        }

        return substr($stem, $pos + 1);
    }
}

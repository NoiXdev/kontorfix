<?php

namespace App\Services\Vcs;

use App\Enums\PackageType;
use App\Models\Package;
use App\Services\Python\PythonName;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Imports versions into a package by mirroring the tags of its git repository. Composer
 * stores a PackageVersion per tag; Python builds an sdist per tag and stores a PythonDist
 * row. Building source archives with `git archive` mirrors what Composer already does —
 * no build/prepare scripts are run, so publish mode remains the way to ship pre-built
 * artifacts.
 *
 * npm is never git-sourced (`PackageSourceMode::allowedFor(PackageType::Npm)` excludes
 * `Git`, enforced on both create paths and, as a backstop for rows that predate the rule,
 * in `SyncPackage::handle()` before this class is ever reached) — a built npm tarball is
 * not the repository tree, so mirroring tags would silently ship the wrong content. The
 * `PackageType::Npm` arm below exists only to keep this match exhaustive and to fail loudly
 * if that invariant is ever broken upstream.
 */
class GitSourceImporter
{
    public function import(Package $package, GitRepository $repo): void
    {
        $parser = new VersionParser;

        foreach ($repo->tags() as $tag) {
            try {
                $normalized = $parser->normalize($tag);
            } catch (UnexpectedValueException) {
                continue; // not a version tag
            }

            match ($package->type) {
                PackageType::Composer => $this->importManifestVersion($package, $repo, $tag, $normalized, 'composer.json'),
                PackageType::Npm => throw new \LogicException('npm packages are never git-sourced; SyncPackage::handle() must guard this before calling import().'),
                PackageType::Python => $this->importPythonDist($package, $repo, $tag),
            };
        }
    }

    /**
     * Composer: a PackageVersion carrying the raw composer.json as metadata. The dist zip
     * is built lazily on first download (unchanged behaviour).
     */
    private function importManifestVersion(Package $package, GitRepository $repo, string $tag, string $normalized, string $manifestFile): void
    {
        $manifest = $this->readJsonManifest($repo, $tag, $manifestFile);
        if ($manifest === null) {
            return; // tag without a (valid) manifest — skip, don't abort the whole sync
        }

        $package->versions()->updateOrCreate(
            ['version' => $normalized],
            [
                'version_pretty' => $tag,
                'source_reference' => $repo->commitFor($tag),
                'metadata' => $manifest,
                'released_at' => $repo->committedAt($tag),
            ],
        );
    }

    /**
     * Python: an sdist built from the tag, stored as a PythonDist so it is served over the
     * PEP 503 simple API with a real sha256 (pip verifies it). The version is the tag with
     * an optional leading "v" stripped.
     */
    private function importPythonDist(Package $package, GitRepository $repo, string $tag): void
    {
        $version = ltrim($tag, 'vV');
        if ($version === '' || strlen($version) > 100) {
            return;
        }

        // PEP 625 sdist filename: normalised name with separators collapsed to "_".
        $sdistName = str_replace('-', '_', PythonName::normalize($package->name));
        $filename = "{$sdistName}-{$version}.tar.gz";
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*\.tar\.gz$/', $filename) || str_contains($filename, '..')) {
            return;
        }

        $commit = $repo->commitFor($tag);
        $existing = $package->pythonDists()->where('filename', $filename)->first();
        if ($existing !== null && $existing->source_reference === $commit) {
            return; // already built from this exact commit
        }

        $built = $this->buildArchive($package, $repo, $tag, "{$sdistName}-{$version}/", "pypi/{$package->id}/{$filename}");

        $package->pythonDists()->updateOrCreate(
            ['filename' => $filename],
            [
                'version' => $version,
                'filetype' => 'sdist',
                'path' => $built['path'],
                'source_reference' => $commit,
                'sha256' => hash('sha256', $built['bytes']),
                'size' => strlen($built['bytes']),
                'uploaded_at' => now(),
            ],
        );
    }

    /**
     * @return array{bytes: string, path: string}
     */
    private function buildArchive(Package $package, GitRepository $repo, string $ref, string $prefix, string $path): array
    {
        $tmp = $repo->archiveTarGz($ref, $prefix);

        try {
            $bytes = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }

        $disk = Storage::disk('artifacts');
        $staging = dirname($path).'/.'.basename($path).'.'.Str::random(8).'.part';
        $disk->put($staging, $bytes);
        $disk->move($staging, $path);

        return ['bytes' => $bytes, 'path' => $path];
    }

    /**
     * @return array<string, mixed>|null decoded manifest, or null when absent/invalid
     */
    private function readJsonManifest(GitRepository $repo, string $tag, string $file): ?array
    {
        try {
            $decoded = json_decode($repo->fileAtRef($tag, $file), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}

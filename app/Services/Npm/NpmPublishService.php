<?php

namespace App\Services\Npm;

use App\Exceptions\VersionConflictException;
use App\Models\Package;
use App\Models\PackageVersion;
use Composer\Semver\VersionParser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class NpmPublishService
{
    /** Upper limit for a single tarball (default 100 MB), against memory/disk DoS. */
    private function maxTarballBytes(): int
    {
        return (int) config('kontorfix.npm_max_tarball_bytes', 100 * 1024 * 1024);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function publish(Package $package, array $body): PackageVersion
    {
        // Body name must match the package.
        if (($body['name'] ?? null) !== $package->name) {
            throw new InvalidArgumentException('Body name does not match package.');
        }

        $versions = is_array($body['versions'] ?? null) ? $body['versions'] : [];
        $attachments = is_array($body['_attachments'] ?? null) ? $body['_attachments'] : [];
        if (count($versions) !== 1 || count($attachments) !== 1) {
            throw new InvalidArgumentException('Exactly one version and one attachment expected.');
        }

        $versionString = (string) array_key_first($versions);
        $manifest = is_array($versions[$versionString]) ? $versions[$versionString] : [];
        $attachmentKey = (string) array_key_first($attachments);

        // Version must be valid semver (otherwise Semver::rsort in the packument breaks later).
        try {
            (new VersionParser)->normalize($versionString);
        } catch (\UnexpectedValueException) {
            throw new InvalidArgumentException('Invalid version string.');
        }

        // Derive the storage filename ourselves instead of trusting npm's attachment key
        // (which for scoped packages is "@scope/name-version.tgz", i.e. contains @ and /).
        // strrchr/substr only returns the last path segment → structurally traversal-free.
        // The subsequent regex is deliberately narrow: versions with build metadata (1.0.0+x)
        // or uppercase letters in the pre-release fail here (fail-closed) — fine for v0.2.
        $unscoped = str_contains($package->name, '/') ? substr((string) strrchr($package->name, '/'), 1) : $package->name;
        $file = "{$unscoped}-{$versionString}.tgz";
        if (! preg_match('/^[a-z0-9][a-z0-9._~-]*\.tgz$/', $file) || str_contains($file, '..')) {
            throw new InvalidArgumentException('Cannot derive a safe tarball filename for this package/version.');
        }

        if ($package->versions()->where('version', $versionString)->exists()) {
            throw new VersionConflictException('Version already exists.');
        }

        $data = $attachments[$attachmentKey]['data'] ?? '';
        $bytes = base64_decode(is_string($data) ? $data : '', true);
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('Invalid or empty attachment data.');
        }
        if (strlen($bytes) > $this->maxTarballBytes()) {
            throw new InvalidArgumentException('Tarball exceeds the maximum allowed size.');
        }

        $shasum = sha1($bytes);
        $integrity = 'sha512-'.base64_encode(hash('sha512', $bytes, true));
        $path = "tarballs/{$package->id}/{$file}";

        // Write atomically (staging -> move), as with the Composer dist.
        $disk = Storage::disk('artifacts');
        $staging = "tarballs/{$package->id}/.{$file}.".uniqid().'.part';
        $disk->put($staging, $bytes);
        $disk->move($staging, $path);

        try {
            $version = $package->versions()->create([
                'version' => $versionString,
                'version_pretty' => $versionString,
                'source_reference' => null,
                'metadata' => $manifest,
                'dist_shasum' => $shasum,
                'dist_integrity' => $integrity,
                'dist_tarball_name' => $file,
                'dist_path' => $path,
                'released_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Race: a concurrent publish of the same version won the exists() check
            // between check and insert — the unique index catches the loser.
            @$disk->delete($path);
            throw new VersionConflictException('Version already exists.', previous: $e);
        }

        // Merge dist-tags.
        $tags = $package->dist_tags ?? [];
        foreach ((is_array($body['dist-tags'] ?? null) ? $body['dist-tags'] : []) as $tag => $v) {
            $tags[(string) $tag] = (string) $v;
        }
        $package->update(['dist_tags' => $tags]);

        return $version;
    }
}

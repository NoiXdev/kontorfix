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
    /** Obergrenze für einen einzelnen Tarball (Default 100 MB), gegen Memory-/Disk-DoS. */
    private function maxTarballBytes(): int
    {
        return (int) config('kontorfix.npm_max_tarball_bytes', 100 * 1024 * 1024);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function publish(Package $package, array $body): PackageVersion
    {
        // Body-Name muss zum Paket passen.
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
        $file = (string) array_key_first($attachments);

        // Version muss gültiges Semver sein (sonst bricht später Semver::rsort im packument).
        try {
            (new VersionParser)->normalize($versionString);
        } catch (\UnexpectedValueException) {
            throw new InvalidArgumentException('Invalid version string.');
        }

        // Dateiname streng validieren — kein Pfad-Traversal in den Storage-Key.
        // Lowercase-only, damit Publish und die (case-sensitive) Tarball-Route übereinstimmen.
        if (! preg_match('/^[a-z0-9][a-z0-9._~-]*\.tgz$/', $file) || str_contains($file, '..')) {
            throw new InvalidArgumentException('Invalid tarball filename.');
        }

        if ($package->versions()->where('version', $versionString)->exists()) {
            throw new VersionConflictException('Version already exists.');
        }

        $data = $attachments[$file]['data'] ?? '';
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

        // Atomar schreiben (staging -> move), wie beim Composer-Dist.
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
            // Race: ein paralleler Publish derselben Version hat die exists()-Prüfung
            // zwischen Check und Insert gewonnen — der Unique-Index fängt den Verlierer.
            @$disk->delete($path);
            throw new VersionConflictException('Version already exists.', previous: $e);
        }

        // dist-tags mergen.
        $tags = $package->dist_tags ?? [];
        foreach ((is_array($body['dist-tags'] ?? null) ? $body['dist-tags'] : []) as $tag => $v) {
            $tags[(string) $tag] = (string) $v;
        }
        $package->update(['dist_tags' => $tags]);

        return $version;
    }
}

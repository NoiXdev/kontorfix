<?php

namespace App\Services\Npm;

use App\Exceptions\VersionConflictException;
use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class NpmPublishService
{
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

        // Dateiname streng validieren — kein Pfad-Traversal in den Storage-Key.
        if (! preg_match('/^[a-z0-9][a-z0-9._~-]*\.tgz$/i', $file) || str_contains($file, '..')) {
            throw new InvalidArgumentException('Invalid tarball filename.');
        }

        if ($package->versions()->where('version', $versionString)->exists()) {
            throw new VersionConflictException('Version already exists.');
        }

        $data = $attachments[$file]['data'] ?? '';
        $bytes = base64_decode(is_string($data) ? $data : '', true);
        if ($bytes === false) {
            throw new InvalidArgumentException('Invalid attachment data.');
        }

        $shasum = sha1($bytes);
        $integrity = 'sha512-'.base64_encode(hash('sha512', $bytes, true));
        $path = "tarballs/{$package->id}/{$file}";

        // Atomar schreiben (staging -> move), wie beim Composer-Dist.
        $disk = Storage::disk('artifacts');
        $staging = "tarballs/{$package->id}/.{$file}.".uniqid().'.part';
        $disk->put($staging, $bytes);
        $disk->move($staging, $path);

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

        // dist-tags mergen.
        $tags = $package->dist_tags ?? [];
        foreach ((is_array($body['dist-tags'] ?? null) ? $body['dist-tags'] : []) as $tag => $v) {
            $tags[(string) $tag] = (string) $v;
        }
        $package->update(['dist_tags' => $tags]);

        return $version;
    }
}

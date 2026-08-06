<?php

namespace App\Services\Python;

use App\Exceptions\VersionConflictException;
use App\Models\Package;
use App\Models\PythonDist;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stores an uploaded Python distribution (twine / distutils "file_upload"). PyPI is
 * file-centric — each upload is one sdist or wheel — so this creates a PythonDist row
 * per file rather than a version row.
 */
class PythonPublishService
{
    private function maxBytes(): int
    {
        return (int) config('kontorfix.python_max_dist_bytes', 200 * 1024 * 1024);
    }

    /**
     * @param  array<string, mixed>  $meta  The multipart form fields twine sends alongside the file.
     */
    public function publish(Package $package, UploadedFile $file, array $meta): PythonDist
    {
        // The uploaded project name must match the target package (normalised comparison).
        $metaName = is_string($meta['name'] ?? null) ? $meta['name'] : '';
        if (PythonName::normalize($metaName) !== PythonName::normalize($package->name)) {
            throw new InvalidArgumentException('Uploaded project name does not match the package.');
        }

        $version = trim((string) ($meta['version'] ?? ''));
        if ($version === '' || strlen($version) > 100) {
            throw new InvalidArgumentException('Missing or invalid version.');
        }

        // Derive a traversal-free filename from the client name and validate its shape.
        $filename = basename($file->getClientOriginalName());
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*\.(whl|tar\.gz|zip)$/', $filename) || str_contains($filename, '..')) {
            throw new InvalidArgumentException('Unsupported or unsafe distribution filename.');
        }

        $filetype = $this->resolveFiletype(is_string($meta['filetype'] ?? null) ? $meta['filetype'] : null, $filename);

        $declaredSize = $file->getSize();
        if ($declaredSize !== false && $declaredSize > $this->maxBytes()) {
            throw new InvalidArgumentException('Distribution exceeds the maximum allowed size.');
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        if ($bytes === '') {
            throw new InvalidArgumentException('Empty distribution upload.');
        }
        if (strlen($bytes) > $this->maxBytes()) {
            throw new InvalidArgumentException('Distribution exceeds the maximum allowed size.');
        }

        $sha256 = hash('sha256', $bytes);

        // twine sends the digest it computed — reject a mismatch (corruption / tampering).
        $claimed = is_string($meta['sha256_digest'] ?? null) ? strtolower(trim($meta['sha256_digest'])) : null;
        if ($claimed !== null && $claimed !== '' && ! hash_equals($sha256, $claimed)) {
            throw new InvalidArgumentException('sha256_digest does not match the uploaded content.');
        }

        if ($package->pythonDists()->where('filename', $filename)->exists()) {
            throw new VersionConflictException('This distribution file already exists.');
        }

        $path = "pypi/{$package->id}/{$filename}";
        $disk = Storage::disk('artifacts');
        $staging = "pypi/{$package->id}/.{$filename}.".Str::random(8).'.part';
        $disk->put($staging, $bytes);
        $disk->move($staging, $path);

        try {
            return $package->pythonDists()->create([
                'version' => $version,
                'filename' => $filename,
                'filetype' => $filetype,
                'path' => $path,
                'sha256' => $sha256,
                'size' => strlen($bytes),
                'requires_python' => is_string($meta['requires_python'] ?? null) && $meta['requires_python'] !== '' ? $meta['requires_python'] : null,
                'uploaded_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Lost a race against a concurrent identical upload — the unique index caught it.
            @$disk->delete($path);
            throw new VersionConflictException('This distribution file already exists.', previous: $e);
        }
    }

    private function resolveFiletype(?string $claimed, string $filename): string
    {
        if (in_array($claimed, ['sdist', 'bdist_wheel'], true)) {
            return $claimed;
        }

        return str_ends_with($filename, '.whl') ? 'bdist_wheel' : 'sdist';
    }
}

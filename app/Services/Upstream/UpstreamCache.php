<?php

namespace App\Services\Upstream;

use App\Models\Upstream;
use Illuminate\Support\Facades\Storage;

class UpstreamCache
{
    /**
     * @return array<string, mixed>|null null bei Miss oder Ablauf
     */
    public function getMetadata(Upstream $upstream, string $packageName): ?array
    {
        $ttl = (int) config('kontorfix.upstream_cache_ttl', 300);
        $row = $upstream->metadataCache()->where('package_name', $packageName)->first();

        if ($row === null || $row->fetched_at->lt(now()->subSeconds($ttl))) {
            return null;
        }

        return $row->payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function putMetadata(Upstream $upstream, string $packageName, array $payload): void
    {
        $upstream->metadataCache()->updateOrCreate(
            ['package_name' => $packageName],
            ['payload' => $payload, 'fetched_at' => now()],
        );
    }

    public function hasArtifact(string $path): bool
    {
        return Storage::disk('artifacts')->exists($path);
    }

    public function putArtifact(string $path, string $bytes): void
    {
        // Atomar: staging -> move.
        $disk = Storage::disk('artifacts');
        $staging = $path.'.'.uniqid().'.part';
        $disk->put($staging, $bytes);
        $disk->move($staging, $path);
    }
}

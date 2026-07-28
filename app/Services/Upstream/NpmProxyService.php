<?php

namespace App\Services\Upstream;

use App\Models\Group;
use App\Models\Upstream;

class NpmProxyService
{
    public function __construct(
        private readonly UpstreamClient $client,
        private readonly UpstreamCache $cache,
    ) {}

    /**
     * @return array<string, mixed>|null null if not found or (strict mode) not allowed
     */
    public function packument(Group $group, Upstream $upstream, string $packageName, string $registryBaseUrl): ?array
    {
        if (! $upstream->allowsPackage($packageName)) {
            return null;
        }

        $payload = $this->cache->getMetadata($upstream, $packageName);
        if ($payload === null) {
            $payload = $this->client->getJson($upstream, '/'.$packageName);
            if ($payload === null) {
                return null;
            }
            $this->cache->putMetadata($upstream, $packageName, $payload);
        }

        $registryBaseUrl = rtrim($registryBaseUrl, '/');
        $versions = $payload['versions'] ?? [];

        $rewritten = array_map(function (array $version) use ($upstream, $packageName, $registryBaseUrl): array {
            if (! isset($version['dist']) || ! is_array($version['dist'])) {
                return $version;
            }

            $original = $version['dist']['tarball'] ?? null;
            if ($original === null) {
                return $version;
            }

            $file = basename((string) parse_url($original, PHP_URL_PATH));
            $version['dist']['tarball'] = "{$registryBaseUrl}/proxy/npm/{$upstream->id}/{$packageName}/-/{$file}";

            return $version;
        }, $versions);

        return [
            'name' => $payload['name'] ?? $packageName,
            'dist-tags' => $payload['dist-tags'] ?? [],
            'versions' => $rewritten,
        ];
    }
}

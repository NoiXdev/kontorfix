<?php

namespace App\Services\Upstream;

use App\Enums\UpstreamPolicy;
use App\Models\Group;
use App\Models\Upstream;
use Composer\MetadataMinifier\MetadataMinifier;

class ComposerProxyService
{
    public function __construct(
        private readonly UpstreamClient $client,
        private readonly UpstreamCache $cache,
    ) {}

    /**
     * @return array<string, mixed>|null null wenn nicht gefunden oder (Strict-Modus) nicht erlaubt
     */
    public function metadata(Group $group, Upstream $upstream, string $packageName, string $baseUrl): ?array
    {
        if ($upstream->policy === UpstreamPolicy::Strict
            && ! $upstream->allowedPackages()->where('name', $packageName)->exists()
        ) {
            return null;
        }

        $payload = $this->cache->getMetadata($upstream, $packageName);
        if ($payload === null) {
            $payload = $this->client->getJson($upstream, "/p2/{$packageName}.json");
            if ($payload === null) {
                return null;
            }
            $this->cache->putMetadata($upstream, $packageName, $payload);
        }

        $minifiedVersions = $payload['packages'][$packageName] ?? [];
        $versions = MetadataMinifier::expand($minifiedVersions);
        $baseUrl = rtrim($baseUrl, '/');

        $rewritten = array_map(function (array $version) use ($group, $upstream, $packageName, $baseUrl): array {
            // Source-only-Versionen (ohne dist) bekommen keinen fabrizierten Dist-Eintrag —
            // sonst würde der Proxy-Download für ein nie existentes Archiv angefragt.
            if (! isset($version['dist']) || ! is_array($version['dist'])) {
                return $version;
            }

            $identifier = $version['version_normalized'] ?? $version['version'];
            $version['dist'] = [
                'type' => $version['dist']['type'] ?? 'zip',
                'url' => "{$baseUrl}/r/{$group->slug}/proxy/composer/{$upstream->id}/{$packageName}/{$identifier}",
                'reference' => $version['dist']['reference'] ?? null,
            ];

            return $version;
        }, $versions);

        return [
            'minified' => 'composer/2.0',
            'packages' => [$packageName => MetadataMinifier::minify($rewritten)],
        ];
    }
}

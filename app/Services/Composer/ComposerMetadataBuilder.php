<?php

namespace App\Services\Composer;

use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use Composer\MetadataMinifier\MetadataMinifier;

class ComposerMetadataBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Package $package, Group $group, string $baseUrl): array
    {
        $versions = $package->versions()->get()
            ->map(function (PackageVersion $v) use ($package, $group, $baseUrl): array {
                $entry = array_merge($v->metadata ?? [], [
                    'name' => $package->name,
                    'version' => $v->version_pretty,
                    'version_normalized' => $v->version,
                    'dist' => [
                        'type' => 'zip',
                        'url' => "{$baseUrl}/r/{$group->slug}/dists/{$package->name}/{$v->version}.zip",
                        'reference' => $v->source_reference,
                    ],
                ]);

                if ($package->repository_url !== null) {
                    $entry['source'] = [
                        'type' => 'git',
                        'url' => $package->repository_url,
                        'reference' => $v->source_reference,
                    ];
                }

                return $entry;
            })
            ->all();

        return ['packages' => [$package->name => MetadataMinifier::minify(array_values($versions))]];
    }
}

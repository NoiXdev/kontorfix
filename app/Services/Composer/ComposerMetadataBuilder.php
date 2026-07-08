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
        $baseUrl = rtrim($baseUrl, '/');

        $versions = $package->versions()->get()
            ->map(function (PackageVersion $v) use ($package, $group, $baseUrl): array {
                // Das komplette composer.json des Tags wird durchgereicht (wie Packagist);
                // name/version/dist/source werden autoritativ von uns überschrieben, damit
                // ein bösartiges Tag weder die Dist-URL noch die Version fälschen kann.
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

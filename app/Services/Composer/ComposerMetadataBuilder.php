<?php

namespace App\Services\Composer;

use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Support\CredentialUrl;
use Composer\MetadataMinifier\MetadataMinifier;

class ComposerMetadataBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Package $package, Group $group, string $registryBaseUrl): array
    {
        $registryBaseUrl = rtrim($registryBaseUrl, '/');

        $versions = $package->versions()->get()
            ->map(function (PackageVersion $v) use ($package, $registryBaseUrl): array {
                // The tag's complete composer.json is passed through (like Packagist);
                // name/version/dist/source are authoritatively overwritten by us, so
                // a malicious tag can forge neither the dist URL nor the version.
                $entry = array_merge($v->metadata ?? [], [
                    'name' => $package->name,
                    'version' => $v->version_pretty,
                    'version_normalized' => $v->version,
                    'dist' => [
                        'type' => 'zip',
                        'url' => "{$registryBaseUrl}/dists/{$package->name}/{$v->version}.zip",
                        'reference' => $v->source_reference,
                    ],
                ]);

                if ($package->repository_url !== null) {
                    $entry['source'] = [
                        'type' => 'git',
                        // Widest reader set in the product: every registry read token, and
                        // anonymous clients when the group is public. A PAT written as
                        // userinfo must never reach a package manager's lock file.
                        'url' => CredentialUrl::redact($package->repository_url),
                        'reference' => $v->source_reference,
                    ];
                }

                return $entry;
            })
            ->all();

        return ['packages' => [$package->name => MetadataMinifier::minify(array_values($versions))]];
    }
}

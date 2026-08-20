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

        $notice = $package->abandonmentNotice();

        $versions = $package->versions()->get()
            ->map(function (PackageVersion $v) use ($package, $registryBaseUrl, $notice): array {
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

                // The registry owns this field. A malicious tag's composer.json could declare
                // itself abandoned (with an attacker-chosen replacement) or, for a package the
                // operator once marked and then un-marked, could still carry a stale `abandoned`
                // key from when the manifest was mirrored — either way, the mirrored manifest
                // must not be able to plant or resurrect this notice, which the array_merge
                // above would otherwise pass through. Mirrors NpmMetadataBuilder::build().
                unset($entry['abandoned']);

                // Composer reads this off each version entry. The minifier collapses it onto the
                // first one; expansion restores it to all of them, which is how Packagist serves
                // an abandoned package.
                if ($notice !== null) {
                    $entry['abandoned'] = $notice->composerValue();
                }

                return $entry;
            })
            ->all();

        return ['packages' => [$package->name => MetadataMinifier::minify(array_values($versions))]];
    }
}

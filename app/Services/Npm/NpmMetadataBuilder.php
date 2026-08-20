<?php

namespace App\Services\Npm;

use App\Models\Package;
use App\Models\PackageVersion;
use Composer\Semver\Semver;

class NpmMetadataBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Package $package, string $registryBaseUrl): array
    {
        $registryBaseUrl = rtrim($registryBaseUrl, '/');
        $notice = $package->abandonmentNotice();
        $versions = [];

        foreach ($package->versions()->get() as $v) {
            /** @var PackageVersion $v */
            // The version's complete package.json is passed through; name/version/dist
            // are authoritatively overwritten by us, so a malicious version can forge
            // neither the tarball URL nor the version.
            $manifest = array_merge($v->metadata ?? [], [
                'name' => $package->name,
                'version' => $v->version,
                'dist' => array_filter([
                    'tarball' => "{$registryBaseUrl}/{$package->name}/-/{$v->dist_tarball_name}",
                    'shasum' => $v->dist_shasum,
                    'integrity' => $v->dist_integrity,
                ], fn (mixed $x): bool => $x !== null),
            ]);

            // The registry owns this field. npm has no structured replacement, so the whole
            // sentence is composed here — and an uploaded package.json must not be able to
            // plant its own deprecation notice, which the array_merge above would otherwise
            // pass through.
            unset($manifest['deprecated']);
            if ($notice !== null) {
                $manifest['deprecated'] = $notice->message();
            }

            $versions[$v->version] = $manifest;
        }

        return [
            'name' => $package->name,
            'dist-tags' => $this->distTags($package, array_map('strval', array_keys($versions))),
            'versions' => $versions,
        ];
    }

    /**
     * @param  list<string>  $versionList
     * @return array<string, string>
     */
    private function distTags(Package $package, array $versionList): array
    {
        $tags = $package->dist_tags ?? [];
        if (! isset($tags['latest']) && $versionList !== []) {
            $sorted = Semver::rsort($versionList);
            $tags['latest'] = $sorted[0];
        }

        return $tags;
    }
}

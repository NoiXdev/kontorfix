<?php

namespace App\Services\Composer;

use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Support\CredentialUrl;
use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Semver\Semver;

class ComposerMetadataBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Package $package, Group $group, string $registryBaseUrl): array
    {
        return $this->document($package, $registryBaseUrl, null);
    }

    /**
     * The org endpoint's counterpart of build(): same document shape, but the served
     * versions are the UNION of every group's `version_constraint` for this package rather
     * than everything unfiltered (spec decision 7) — the group path above has never applied
     * `version_constraint` at all (the column is unused there; see
     * App\Http\Controllers\Concerns\GuardsPackageAttachment's docblock), so this is the
     * first caller that reads it, and it reads it only for the org aggregate.
     *
     * $versionConstraints is every unexpired assignment's constraint value for this package
     * across the organization's groups
     * (RegistryAccessService::versionConstraintsForOrganization()) — a plain list rather
     * than a Collection; see that method's docblock for why. A `null` among them — at least
     * one group assigning the package with NO restriction — makes the union unfiltered
     * outright, because that single group would itself serve everything. Otherwise a
     * version is served when it satisfies ANY of the constraints (an OR across groups, the
     * "most permissive" reading decision 7 asks for), using the same `composer/semver`
     * package already a project dependency (no in-house constraint parser is introduced
     * here).
     *
     * @param  list<string|null>  $versionConstraints
     * @return array<string, mixed>
     */
    public function buildForOrganization(Package $package, string $registryBaseUrl, array $versionConstraints): array
    {
        // Empty is defensive rather than expected: organizationPackage() only ever returns
        // a package with at least one live assignment, so there is always at least one
        // constraint value (possibly null) to look at. Treated as unfiltered rather than
        // "filter out everything", the fail-open reading consistent with a null entry.
        if ($versionConstraints === [] || in_array(null, $versionConstraints, true)) {
            return $this->document($package, $registryBaseUrl, null);
        }

        /** @var list<string> $constraints no null survives the guard above */
        $constraints = $versionConstraints;

        return $this->document(
            $package,
            $registryBaseUrl,
            fn (PackageVersion $v): bool => collect($constraints)->contains(
                fn (string $constraint): bool => Semver::satisfies($v->version_pretty, $constraint)
            ),
        );
    }

    /**
     * The document both build() and buildForOrganization() produce — identical except for
     * which versions of $package are included. $matches, when given, is applied to the raw
     * PackageVersion rows (BEFORE dist URLs / abandonment are computed), so a version the
     * org endpoint filters out is invisible in every respect, not merely unlisted.
     *
     * @param  (callable(PackageVersion): bool)|null  $matches  null = unfiltered (build()'s
     *                                                          byte-identical group behavior)
     * @return array<string, mixed>
     */
    private function document(Package $package, string $registryBaseUrl, ?callable $matches): array
    {
        $registryBaseUrl = rtrim($registryBaseUrl, '/');

        $notice = $package->abandonmentNotice();

        $versionRows = $package->versions()->get();
        if ($matches !== null) {
            $versionRows = $versionRows->filter($matches);
        }

        $versions = $versionRows
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

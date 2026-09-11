<?php

namespace App\Services\Composer;

use App\Enums\PackageType;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\Licence\VersionEntitlement;
use App\Support\CredentialUrl;
use App\Support\Licence\VersionBounds;
use App\Support\Licence\VersionWindows;
use Closure;
use Composer\MetadataMinifier\MetadataMinifier;

class ComposerMetadataBuilder
{
    public function __construct(private readonly VersionEntitlement $entitlement) {}

    /**
     * $bounds is the already-resolved licence window for this group's assignment (see
     * VersionEntitlement::boundsFor()) — this class stays free of access/licence lookups and
     * only asks permits() whether a given served version falls inside it.
     *
     * @return array<string, mixed>
     */
    public function build(Package $package, string $registryBaseUrl, VersionBounds $bounds): array
    {
        return $this->document(
            $package,
            $registryBaseUrl,
            fn (string $version): bool => $this->entitlement->permits($bounds, PackageType::Composer, $version),
        );
    }

    /**
     * The org endpoint's counterpart of build() — same document, no Group parameter (the org
     * aggregate has none). $windows is the union of every unexpired assignment's window
     * across the organization's groups (VersionEntitlement::windowsForOrganization()), asked
     * via permitsAny() rather than permits() so a version admitted by ANY one group's window
     * is served, never a hull of the windows.
     *
     * `group_package.version_constraint` (a distinct column from the `version_min`/
     * `version_max` bounds `$windows` carries) is still not enforced at serve time on ANY
     * path today — it is written nowhere and read nowhere else in the app (see
     * App\Http\Controllers\Concerns\GuardsPackageAttachment's docblock, "the fuse is in the
     * schema and only the endpoints are missing"). That is unrelated to the licence-bounds
     * filtering below, which both build() and this method now apply identically.
     *
     * @return array<string, mixed>
     */
    public function buildForOrganization(Package $package, string $registryBaseUrl, VersionWindows $windows): array
    {
        return $this->document(
            $package,
            $registryBaseUrl,
            fn (string $version): bool => $this->entitlement->permitsAny($windows, PackageType::Composer, $version),
        );
    }

    /**
     * The document both build() and buildForOrganization() produce — identical in every
     * respect once a Package, a base URL and a permits predicate are fixed. $permits decides,
     * per version, whether the caller's licence admits it; an out-of-licence version is
     * dropped from $versions entirely (HIDDEN, not merely refused on download) so it never
     * appears in the metadata a package manager resolves against.
     *
     * @param  Closure(string): bool  $permits
     * @return array<string, mixed>
     */
    private function document(Package $package, string $registryBaseUrl, Closure $permits): array
    {
        $registryBaseUrl = rtrim($registryBaseUrl, '/');

        $notice = $package->abandonmentNotice();

        $versions = $package->versions()->get()
            ->filter(fn (PackageVersion $v): bool => $permits($v->version))
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

<?php

namespace App\Services\Npm;

use App\Enums\PackageType;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\Licence\VersionEntitlement;
use App\Support\Licence\VersionBounds;
use App\Support\Licence\VersionWindows;
use Closure;
use Composer\Semver\Semver;

class NpmMetadataBuilder
{
    public function __construct(private readonly VersionEntitlement $entitlement) {}

    /**
     * $bounds is the already-resolved licence window for this group's assignment (see
     * VersionEntitlement::boundsFor()) — mirrors ComposerMetadataBuilder::build(): this class
     * stays free of access/licence lookups and only asks permits() whether a given served
     * version falls inside it.
     *
     * @return array<string, mixed>
     */
    public function build(Package $package, string $registryBaseUrl, VersionBounds $bounds): array
    {
        return $this->document(
            $package,
            $registryBaseUrl,
            fn (string $version): bool => $this->entitlement->permits($bounds, PackageType::Npm, $version),
        );
    }

    /**
     * The org endpoint's counterpart of build() — mirrors
     * ComposerMetadataBuilder::buildForOrganization(): same document, no Group parameter, and
     * $windows (the union of every unexpired assignment's window across the organization's
     * groups) is asked via permitsAny() so a version admitted by ANY one group's window is
     * served, never a hull of the windows.
     *
     * @return array<string, mixed>
     */
    public function buildForOrganization(Package $package, string $registryBaseUrl, VersionWindows $windows): array
    {
        return $this->document(
            $package,
            $registryBaseUrl,
            fn (string $version): bool => $this->entitlement->permitsAny($windows, PackageType::Npm, $version),
        );
    }

    /**
     * The document both build() and buildForOrganization() produce — identical in every
     * respect once a Package, a base URL and a permits predicate are fixed. $permits decides,
     * per version, whether the caller's licence admits it; an out-of-licence version is
     * dropped from `versions` entirely (HIDDEN, not merely refused on download), mirroring
     * ComposerMetadataBuilder::document().
     *
     * npm's own wrinkle, with no Composer counterpart: `dist-tags` is built from the SURVIVING
     * version set, never the full one — a tag (typically `latest`) pointing at a version this
     * licence excludes would otherwise make `npm install` resolve a tag to a version whose
     * metadata isn't there, which is exactly the breakage the hidden-not-403 approach exists
     * to avoid. See distTags() below.
     *
     * @param  Closure(string): bool  $permits
     * @return array<string, mixed>
     */
    private function document(Package $package, string $registryBaseUrl, Closure $permits): array
    {
        $registryBaseUrl = rtrim($registryBaseUrl, '/');
        $notice = $package->abandonmentNotice();
        $versions = [];

        foreach ($package->versions()->get() as $v) {
            /** @var PackageVersion $v */
            if (! $permits($v->version)) {
                continue;
            }

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

        $tags = $this->distTags($package, array_map('strval', array_keys($versions)));

        // npm's packument shape has both `versions` and `dist-tags` as a JSON OBJECT, never
        // an array — but PHP's json_encode() renders an empty associative array as `[]`. Every
        // non-empty case above already produces a string-keyed array, which encodes as an
        // object correctly; only the empty case (a bounded licence admitting none of the
        // package's versions — a valid, 200-worthy state, not an error) needs the explicit
        // cast, done here rather than relying on `versions`/`tags` never being empty.
        return [
            'name' => $package->name,
            'dist-tags' => $tags === [] ? (object) [] : $tags,
            'versions' => $versions === [] ? (object) [] : $versions,
        ];
    }

    /**
     * Builds `dist-tags` from the surviving version set $versionList — a version already
     * filtered by document() above, never the package's full release history.
     *
     * Two rules that keep a licence-bounded packument from ever advertising a tag npm cannot
     * resolve:
     *  - a tag (stored on `packages.dist_tags`, e.g. an operator- or publish-set `latest` or
     *    `next`) whose target version did not survive filtering is dropped entirely, rather
     *    than left pointing at a version absent from `versions`;
     *  - `latest` specifically is never simply absent when there is anything to point it at:
     *    if it survived filtering it is kept as-is, and if it did not (or was never set) it is
     *    repointed to the highest surviving version — the same "derive from highest semver"
     *    fallback this method already applied when `dist_tags` carried no `latest` at all, now
     *    also covering the case where the stored `latest` pointed outside the licence.
     *
     * @param  list<string>  $versionList
     * @return array<string, string>
     */
    private function distTags(Package $package, array $versionList): array
    {
        $survivors = array_flip($versionList);
        $tags = array_filter(
            $package->dist_tags ?? [],
            fn (mixed $target): bool => is_string($target) && isset($survivors[$target]),
        );

        if (! isset($tags['latest']) && $versionList !== []) {
            $sorted = Semver::rsort($versionList);
            $tags['latest'] = $sorted[0];
        }

        return $tags;
    }
}

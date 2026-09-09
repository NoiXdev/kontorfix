<?php

namespace App\Services\Oci\Sweeper;

use App\Models\OciManifest;
use App\Models\OciTag;
use App\Models\Package;
use App\Support\Oci\ManifestReferences;
use App\Support\Oci\ReachableSet;
use Carbon\CarbonImmutable;

/**
 * The reachability graph the sweeper walks, per organization.
 *
 * A blob is reachable when a tag points at a manifest that names it — or at an index that
 * points at such a manifest. ROOTED AT TAGS, which differs on purpose from the pull path's
 * blob gate: BlobController::referencedByPackage() roots at MANIFESTS, because an index's
 * children are pushed by digest before anything is tagged and a pull in flight must be able
 * to fetch them. The sweeper cannot use that rooting — under it an orphan manifest would
 * keep its layers alive forever and nothing would ever be collected. The two roots are
 * reconciled by the sweeper also removing unreachable manifests (after the grace period):
 * once it has, manifest-rooted and tag-rooted agree again.
 *
 * PER ORGANIZATION, never globally and never per package. oci_blobs is unique on
 * (organization_id, digest) and carries no package dimension at all — blobs deduplicate per
 * organization by design — so a blob is garbage only when NO tag in ANY package of that
 * organization reaches it.
 *
 * Payloads are parsed once, in one pass, and discarded: only digests and ids are held. A
 * registry with tens of thousands of manifests would not fit its payloads in memory, and it
 * does not have to. The walk itself touches no database.
 */
class OciReachability
{
    /**
     * @param  array<string, CarbonImmutable>  $untaggedWindows  package_id ⇒ cutoff, from
     *                                                           UntaggedRetention::windowsFor(). Checked by created_at against every
     *                                                           manifest of a windowed package, not only untagged ones — a tagged manifest
     *                                                           inside the window is added too, redundantly and harmlessly, since the tag
     *                                                           worklist below already roots it. What the check protects is the untagged
     *                                                           case: the keep_untagged rule keeps a manifest nothing tags, and without
     *                                                           this its layers would be collected out from under it — an image that
     *                                                           resolves and pulls halfway, the exact failure the manifest sweep exists to
     *                                                           prevent, reintroduced by the rule if the roots did not grow with it.
     *                                                           Timestamps only: the rules themselves never reach this class.
     */
    public function forOrganization(string $organizationId, array $untaggedWindows = []): ReachableSet
    {
        $packageIds = Package::query()->where('organization_id', $organizationId)->pluck('id');

        /** @var array<string, array{children: list<string>, blobs: list<string>}> $byId */
        $byId = [];
        /** @var array<string, string> $idByPackageAndDigest */
        $idByPackageAndDigest = [];
        /** @var array<string, string> $packageIdById */
        $packageIdById = [];
        /** @var list<string> $untaggedRoots */
        $untaggedRoots = [];

        OciManifest::query()
            ->whereIn('package_id', $packageIds)
            ->select(['id', 'package_id', 'digest', 'payload', 'created_at'])
            ->lazyById()
            ->each(function (OciManifest $manifest) use (&$byId, &$idByPackageAndDigest, &$packageIdById, &$untaggedRoots, $untaggedWindows): void {
                $byId[$manifest->id] = [
                    'children' => ManifestReferences::childManifestDigests($manifest->payload),
                    'blobs' => ManifestReferences::blobDigests($manifest->payload),
                ];
                // Keyed by package AND digest: a digest is unique only WITHIN a repository,
                // so an index child has to be resolved against its own index's package. Two
                // repositories of one organization holding the same digest is ordinary, not
                // an edge case.
                $idByPackageAndDigest[$manifest->package_id.'@'.$manifest->digest] = $manifest->id;
                $packageIdById[$manifest->id] = $manifest->package_id;

                $window = $untaggedWindows[$manifest->package_id] ?? null;

                if ($window !== null && $manifest->created_at !== null && $manifest->created_at->greaterThanOrEqualTo($window)) {
                    $untaggedRoots[] = $manifest->id;
                }
            });

        // The roots: every manifest a tag points at, plus every manifest of a windowed
        // package still inside that window — tag status is not checked here, so a tagged
        // manifest in-window is added too, redundantly (it is already rooted by the line
        // above). Nothing else.
        $worklist = OciTag::query()->whereIn('package_id', $packageIds)->pluck('manifest_id')->all();

        foreach ($untaggedRoots as $id) {
            $worklist[] = $id;
        }

        $manifestIds = [];
        $blobDigests = [];

        // A worklist to a fixed point, not a fixed-depth descent: an index of indexes is
        // legal, and a one-level walk would answer "unreachable" for a real layer. The
        // `isset` guard doubles as the cycle guard — a payload naming its own digest (a
        // restore artefact; ManifestStore cannot produce one, its digest is the payload's
        // hash) terminates here instead of looping.
        while ($worklist !== []) {
            $id = array_pop($worklist);

            if (isset($manifestIds[$id]) || ! isset($byId[$id])) {
                continue;
            }

            $manifestIds[$id] = true;

            foreach ($byId[$id]['blobs'] as $digest) {
                $blobDigests[$digest] = true;
            }

            foreach ($byId[$id]['children'] as $childDigest) {
                $childId = $idByPackageAndDigest[$packageIdById[$id].'@'.$childDigest] ?? null;

                if ($childId !== null) {
                    $worklist[] = $childId;
                }
            }
        }

        return new ReachableSet($manifestIds, $blobDigests);
    }
}

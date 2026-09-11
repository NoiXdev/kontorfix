<?php

namespace App\Services\Portal;

/**
 * One registry assignment's OWN licence answer, for its OWN organization only: the highest
 * version among the package's own releases that this bound admits, and whether a newer
 * release exists that the bound does not cover.
 *
 * Built by PortalPackages::licenceNoteFor() straight off the one assignment row a customer's
 * own registry carries — never from another organization's bounds on the same shared
 * package, and never a merge across this organization's own several registries either
 * (unlike VersionEntitlement::windowsForOrganization()'s union): each entry answers for
 * exactly the one assignment it belongs to, the same registry-local scoping
 * `PortalRegistryAssignment::$available_until` already keeps.
 *
 * Snake_case on purpose, the same reasoning PortalRegistryAssignment gives its own
 * properties: these are the payload keys Portal\PackageController::index() forwards
 * verbatim, and `portalPackages.ts` reads them by the same names.
 *
 * `highest_permitted` is nullable for the one state a bounded licence can produce with
 * nothing to name: the bounds admit none of the package's existing releases at all (a
 * window sold ahead of any matching release, say). `withheld` stays true there — this
 * assignment still cannot resolve a single release the package has published — with no
 * version to point at.
 */
final readonly class PortalLicenceNote
{
    public function __construct(
        public ?string $highest_permitted,
        public bool $withheld,
    ) {}
}

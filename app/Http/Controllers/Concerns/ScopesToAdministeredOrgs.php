<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Group;
use App\Models\Package;
use App\Services\Scope\OrgScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Helpers that keep per-organization admin controllers inside the organizations the
 * current user may administer, intersected with the active sidebar scope. This is the
 * security boundary that lets a customer-org admin into the console without seeing or
 * touching another organization's data.
 */
trait ScopesToAdministeredOrgs
{
    /**
     * Organization ids the current request may read/list: the active scope, already
     * clamped to the user's administered organizations.
     *
     * @return list<string>
     */
    protected function scopedOrgIds(): array
    {
        return app(OrgScope::class)->ids();
    }

    /** Aborts 403 unless the current user may administer the given organization. */
    protected function assertAdministersOrg(?string $organizationId): void
    {
        abort_unless($organizationId !== null && Auth::user()?->administers($organizationId), 403);
    }

    /** Aborts 403 unless the current user may administer the registry's organization. */
    protected function assertAdministersGroup(Group $group): void
    {
        $this->assertAdministersOrg($group->organization_id);
    }

    /**
     * Constrains a Group query to the active scope. A super-admin viewing "all orgs"
     * gets no filter; everyone else is clamped to their administered organizations.
     *
     * @param  Builder<Group>  $query
     * @return Builder<Group>
     */
    protected function scopeGroupQuery(Builder $query): Builder
    {
        if (app(OrgScope::class)->spansAllOrganizations()) {
            return $query;
        }

        return $query->whereIn('organization_id', $this->scopedOrgIds());
    }

    /**
     * Constrains a Package query to packages reachable in the active scope. Packages have
     * no organization of their own — they belong to one via the registries they are
     * attached to. A super-admin viewing "all orgs" sees every package (including
     * orphans); everyone else only sees packages attached to a registry in one of their
     * administered organizations.
     *
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    protected function scopePackageQuery(Builder $query): Builder
    {
        if (app(OrgScope::class)->spansAllOrganizations()) {
            return $query;
        }

        $orgIds = $this->scopedOrgIds();

        return $query->whereHas('groups', fn (Builder $g) => $g->whereIn('organization_id', $orgIds));
    }

    /** Aborts 403 unless the given package is reachable in the active scope. */
    protected function assertCanTouchPackage(Package $package): void
    {
        if (app(OrgScope::class)->spansAllOrganizations()) {
            return;
        }

        $orgIds = $this->scopedOrgIds();
        $reachable = $package->groups()->whereIn('organization_id', $orgIds)->exists();

        abort_unless($reachable, 403);
    }

    /**
     * Aborts 403 unless every submitted package may be attached by the current user.
     *
     * Packages carry no organization of their own — they belong to one through the
     * registries they are attached to. "Attachable" therefore means: already reachable
     * in the active scope. A package that lives only in another organization's registries
     * is refused outright — otherwise attaching it would hand the caller write access to
     * it via assertCanTouchPackage().
     *
     * A package attached to *no* registry used to be exempt, on the grounds that a freshly
     * created one has no pivot rows yet. That is not what the exemption did. Both create
     * paths attach their `group_ids` in the same request, and a package created with none
     * is invisible to its own creator afterwards — every package listing joins through
     * `groups`. What the exemption really covered was the orphan a deleted registry leaves
     * behind when the pivot rows cascade: claimable by any tenant who learns its id,
     * together with the versions and dists already synced into it, and unrecoverable for
     * the original organization, whose own re-attach then trips the foreign test.
     *
     * Orphans are now attachable only by a super-admin, whose scope spans every
     * organization and who is the party that has to adjudicate the ownership anyway.
     *
     * @param  array<int, string>  $packageIds
     */
    protected function assertCanAttachPackages(array $packageIds): void
    {
        if ($packageIds === [] || app(OrgScope::class)->spansAllOrganizations()) {
            return;
        }

        $orgIds = $this->scopedOrgIds();

        $foreign = Package::whereIn('id', $packageIds)
            ->whereDoesntHave('groups', fn (Builder $g) => $g->whereIn('organization_id', $orgIds))
            ->exists();

        abort_if($foreign, 403);
    }

    /**
     * Resolves the organization a newly created object belongs to: the active scope
     * when one is selected, otherwise the explicitly provided id — validated to be one
     * the user may administer.
     */
    protected function resolveCreationOrg(?string $requested): string
    {
        $active = app(OrgScope::class)->creationOrganizationId();

        // Prefer an explicit choice, then the active scope, then — when neither is set
        // (e.g. a super-admin viewing "all orgs" who didn't pick one) — the user's own
        // home organization. Always validated to be one the user may administer.
        $orgId = $requested ?: ($active ?: Auth::user()?->organization_id);

        $this->assertAdministersOrg($orgId);

        return (string) $orgId;
    }
}

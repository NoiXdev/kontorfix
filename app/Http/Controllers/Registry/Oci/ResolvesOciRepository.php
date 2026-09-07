<?php

namespace App\Http\Controllers\Registry\Oci;

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Exceptions\OciException;
use App\Models\Group;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Services\Registry\OciSettings;
use App\Services\RegistryAccessService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait ResolvesOciRepository
{
    /**
     * The longest repository name push-time creation may store, which is the width of
     * `packages.name` (varchar(255), see 2026_07_08_055350_create_registry_core_tables) and
     * not a policy of this trait's own. Stated here because routes/registry.php's `$ociName`
     * cannot express it: the OCI grammar bounds the SHAPE of a name, never its length, so a
     * 300-character name routes perfectly well and only fails at the INSERT.
     */
    private const MAX_NAME_LENGTH = 255;

    abstract protected function access(): RegistryAccessService;

    protected function ociGroup(Request $request): Group
    {
        /** @var Group $group */
        $group = $request->attributes->get('registryGroup');

        return $group;
    }

    /**
     * The repository name as the CLIENT addressed it: the bare name on a registry domain,
     * and `<org>/<registry>/` plus the bare name when the registry was addressed by path
     * namespace (ResolveOciContext).
     *
     * Every URL this registry hands back — an upload session's `Location`, a finished
     * blob's, a stored manifest's — has to be expressed in the caller's own address space.
     * `{name}` reaches a controller as the BARE name, deliberately, because that is what
     * every lookup is against; a `Location` built from it points at
     * `/v2/meinapp/blobs/uploads/<id>`, which on the instance host names no organization
     * and no registry and is therefore a 404. A client follows an upload Location without
     * asking, so the push simply dies there: `unexpected status from PUT request … 404 Not
     * Found`, which is exactly how this was found — a real `docker push` in bin/e2e, not a
     * reading of the controllers.
     */
    protected function ociAddressedName(Request $request, string $name): string
    {
        return ((string) $request->attributes->get('registryOciNamePrefix')).$name;
    }

    /**
     * The inverse, for a repository name the CLIENT supplied in a request rather than in
     * the path — today only the cross-repository mount's `from=`, which a client writes in
     * the same address space it writes an image reference in.
     *
     * Null when the value names something outside the address this request came in on (a
     * different registry's namespace, or no namespace at all in path mode). The caller
     * treats that exactly as it treats an unknown source repository, which the OCI spec
     * already defines as a fall-through to a normal upload rather than an error — so this
     * cannot be used to probe which namespaces exist.
     */
    protected function ociBareName(Request $request, string $addressed): ?string
    {
        $prefix = (string) $request->attributes->get('registryOciNamePrefix');

        if ($prefix === '') {
            return $addressed;
        }

        return str_starts_with($addressed, $prefix) ? substr($addressed, strlen($prefix)) : null;
    }

    /**
     * The read path. An anonymous caller gets 401 so the client knows to authenticate —
     * UNLESS the group is public, in which case `RegistryAccessService::canAccessGroup()`
     * short-circuits true for a null token and the anonymous caller proceeds to package
     * resolution, exactly as it does for npm/composer/pypi. A caller with a token that
     * simply may not see this registry gets the same NAME_UNKNOWN an absent repository
     * gets, so a token cannot enumerate other tenants' image names by response code alone.
     */
    protected function ociRepository(Request $request, Group $group, string $name): Package
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');

        if (! $this->access()->canAccessGroup($token, $group)) {
            throw $token === null ? OciException::unauthorized() : OciException::nameUnknown($name);
        }

        $package = $this->access()->packagesFor($group)
            ->first(fn (Package $p): bool => $p->type === PackageType::Docker && $p->name === $name);

        if ($package === null) {
            throw OciException::nameUnknown($name);
        }

        return $package;
    }

    /**
     * The write path. Strict and org-bound, with NO public-registry shortcut — a publicly
     * readable registry is not publicly writable, the same rule NpmController::respondPublish
     * states for npm.
     *
     * The repository must already exist, unless an operator has switched push-time creation
     * on (`oci_auto_create_repositories`, off by default). Refusing an unknown name is the
     * same rule NpmController and PypiController enforce — a publish token must not invent
     * names in a registry — but Harbor creates the repository on first push, so the setting
     * exists for an instance that wants that habit. Even when it is on, a name held by
     * ANOTHER organization stays NAME_UNKNOWN: the alternative is a publish token
     * discovering foreign repository names by response code. Everything that lookup cannot
     * answer — including that refusal, and the setting — is ociUnresolvedRepository() below,
     * which states why the order of those checks is the order it is.
     *
     * Resolved strictly within the addressed registry's OWN organization — never through
     * $group->packages(), which carries every package assigned to the group regardless of
     * who owns it, shared ones included, and applies no expiry predicate at all. Sharing
     * hands out reads, never writes (docs/development.md, "Shared packages"; the identical
     * rule NpmController::respondPublish() and PypiController::upload() already enforce): a
     * customer's publish token must not be answered for the operator's shared repository —
     * push, overwrite, or delete an image inside the operator's own organization — nor for
     * an assignment whose `available_until` has lapsed. This was a Critical: unfixed, a
     * publish token for any group a shared Docker repository is assigned to got 201 on
     * `POST .../blobs/uploads/`, 201 on `PUT .../manifests/<ref>` and 202 on
     * `DELETE .../manifests/<digest>` inside the operator's namespace — every other
     * customer assigned that repository then pulls whatever was pushed. A shared name (or
     * an expired one) is now answered exactly like an unknown one, matching
     * NpmController::respondPublish()'s own "own-organization only" resolution and
     * RegistryAccessService::packageBelongsToGroup()'s expiry check.
     */
    protected function ociWritableRepository(Request $request, Group $group, string $name): Package
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');

        if ($token === null) {
            throw OciException::unauthorized();
        }

        if (! $this->access()->canPublishToGroup($token, $group)) {
            throw OciException::denied();
        }

        $package = Package::where('type', PackageType::Docker)
            ->where('name', $name)
            ->where('organization_id', $group->organization_id)
            ->first();

        if ($package !== null && $this->access()->packageBelongsToGroup($group, $package)) {
            return $package;
        }

        return $this->ociUnresolvedRepository($group, $name, $package);
    }

    /**
     * Everything the lookup above could not answer, which is three different shapes:
     *
     *   1. nobody holds the name;
     *   2. the CALLER'S OWN organization holds it, but has not assigned it to this registry
     *      (or the assignment's `available_until` has lapsed);
     *   3. another organization holds it.
     *
     * The setting is consulted before any of the three is distinguished, so a switched-off
     * instance really does answer all three with one identical message. That claim used to
     * be made only in a comment while the code checked membership first, which meant shape 2
     * got the plain `nameUnknown()` and shape 1 got the setting-naming one: a publish token
     * could read the body and tell "this name exists somewhere in my organization" from
     * "this name is free". That channel is intra-organization rather than cross-tenant, and
     * so milder than the cross-tenant one — but it did not exist before push-time creation
     * was added, and the same rule that closed the cross-tenant one closes it: decide the
     * setting first, distinguish afterwards.
     *
     * The price is that shape 2's refusal now also offers the setting as a remedy, which for
     * shape 2 would not help — assigning the repository to this registry is what helps. The
     * message names that remedy first, and naming both is what keeps the three
     * indistinguishable.
     *
     * With the setting ON the shapes are distinguishable by response code (201 vs 404), and
     * deliberately so: the brief's rationale for refusing a foreign name is that a publish
     * token would otherwise discover foreign names, not that the two are indistinguishable.
     *
     * @param  Package|null  $ownedElsewhere  the shape-2 row, if the lookup found one — passed
     *                                        in rather than looked up again so this method
     *                                        cannot disagree with its caller about which
     *                                        shape this is.
     */
    private function ociUnresolvedRepository(Group $group, string $name, ?Package $ownedElsewhere): Package
    {
        if (! app(OciSettings::class)->autoCreateEnabledFor($group->organization)) {
            throw OciException::nameUnknownAutoCreateDisabled($name);
        }

        // Shape 2. Never auto-attached: a publish token must not be able to pull an existing
        // repository into a registry it was not assigned to.
        if ($ownedElsewhere !== null) {
            throw OciException::nameUnknown($name);
        }

        // Shape 3.
        if (Package::where('type', PackageType::Docker)->where('name', $name)->exists()) {
            throw OciException::nameUnknown($name);
        }

        return $this->ociCreateRepository($group, $name);
    }

    /**
     * Push-time creation of a repository nobody registered — the writing half, once
     * ociUnresolvedRepository() has decided that creating is what should happen.
     *
     * A real first `docker push` races itself here. The client uploads layers CONCURRENTLY
     * (`--max-concurrent-uploads`, 5 by default), so several `POST /v2/{name}/blobs/uploads/`
     * requests reach this method for the same brand-new name at the same time. Two windows
     * follow from that, and both are closed here rather than by hoping the requests arrive in
     * order:
     *
     *   - Two of them INSERT. `packages` is unique on `(organization_id, type, name)`, so the
     *     second insert raises a unique violation, which unguarded is an HTTP 500 with an HTML
     *     body — not an OCI error document — and a client that aborts the push. The loser
     *     therefore catches it and re-resolves through the SAME refusals the uncontended path
     *     applies, so losing a race can never hand back a repository the caller may not write
     *     to. Note the constraint's scope: a creator in ANOTHER organization does not collide
     *     at all (organizations may legitimately share a name since
     *     2026_09_02_110000_enforce_package_organization.php), so such a racer is not a winner
     *     to defer to — this push creates its own row, and its 201 says nothing about the
     *     other organization that a free name would not have said.
     *
     *   - One of them INSERTs and another looks the name up before the attach. It would find
     *     a package, fail packageBelongsToGroup(), and answer NAME_UNKNOWN for a repository
     *     that exists. The insert and the attach are therefore one transaction, so no other
     *     connection ever observes the half-built state.
     *
     * NOT SlugClaimGuard's `pg_advisory_xact_lock` shape, deliberately, even though this is
     * the same kind of create-race. That class has to synthesise serialization because its
     * invariant spans two tables — "this slug names no organization AND no registry" is
     * something no index can hold, so without an advisory lock nothing would serialize the two
     * writers at all. Here the invariant IS an index, and PostgreSQL already serializes on it:
     * the second inserter blocks on the unique index until the first commits or rolls back,
     * and then either fails or succeeds correctly. An advisory lock on top would be a second,
     * weaker mechanism for something the database already does properly, and its only visible
     * effect would be to make the collision below rarer — so removing it would redden no test,
     * which is a poor thing to have guarding a Critical.
     */
    private function ociCreateRepository(Group $group, string $name): Package
    {
        // Refused BEFORE the write, and here rather than in the route pattern, because the
        // answer has to be an OCI error body. Unguarded, a name above the column width
        // reached Package::create() and raised SQLSTATE[22001] — the same unrendered
        // QueryException, HTML 500 and per-request stack trace routes/registry.php's own
        // header names as the reason its `$uuid` pattern exists, arriving through the one
        // parameter no pattern can bound. A tighter route pattern would answer the bare
        // `{}` 404 of the /v2 fallback instead, which tells a pushing client nothing.
        //
        // Only the CREATE path needs it: an over-long name on any other path is compared
        // against the column rather than written to it, which Postgres answers with "no
        // such row" and this trait turns into NAME_UNKNOWN, exactly as it should.
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw OciException::nameInvalid($name, self::MAX_NAME_LENGTH);
        }

        try {
            return DB::transaction(function () use ($group, $name): Package {
                $package = Package::create([
                    'organization_id' => $group->organization_id,
                    'type' => PackageType::Docker,
                    // Stated rather than left to the column default. A Docker repository
                    // receives its versions by being pushed to; a row that ends up
                    // git-sourced is never synced and says so only by staying empty.
                    'source_mode' => PackageSourceMode::Publish,
                    'name' => $name,
                ]);

                $group->packages()->attach($package);

                return $package;
            });
        } catch (UniqueConstraintViolationException) {
            // Somebody in this organization won. Re-resolve exactly as ociWritableRepository()
            // does: the winner counts only if it is also assigned to THIS registry — a
            // concurrent push into a sibling registry of the same organization is a winner
            // whose repository this caller still may not write to.
            $winner = Package::where('type', PackageType::Docker)
                ->where('name', $name)
                ->where('organization_id', $group->organization_id)
                ->first();

            if ($winner === null || ! $this->access()->packageBelongsToGroup($group, $winner)) {
                throw OciException::nameUnknown($name);
            }

            return $winner;
        }
    }
}

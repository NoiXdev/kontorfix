<?php

namespace App\Services\Package;

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Package;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * A customer's own package always wins over a shared one of the same name, so an assignment
 * that would put both into one registry is refused rather than accepted and quietly
 * shadowed.
 *
 * Same position the rest of this codebase takes at every boundary where two answers are
 * possible — the ownership migration, the legacy slug redirect: a wrong answer is worse
 * than an error. A customer must never be served the operator's package under a name they
 * own themselves.
 *
 * The rule is stated over the set the registry would serve **after** the write, not over
 * the submission. A rule phrased as "does the submission shadow what is already there"
 * has a direction, and the reverse — assigning the own package into a registry that
 * already serves the shared one — reaches exactly the same end state while satisfying it.
 * A set has no direction, so one predicate over the post-state covers both, and each
 * caller only has to say what its pivot operation leaves behind:
 *
 *   `sync($ids)`                   → the submission (it replaces the set)
 *   `syncWithoutDetaching($ids)`   → the current assignment ∪ the submission
 *   create-then-`sync($ids)`       → the submission (the registry starts empty)
 *
 * Stating it over the post-state also settles the `sync()` case correctly by construction:
 * replacing a registry's own package with the shared one of the same name detaches the own
 * row in the same write, so nothing is shadowed and nothing is refused.
 *
 * `shared === true` already implies operator ownership: only an operator-organization
 * package can be marked shared (see Admin\PackageController::shared), so nothing here
 * re-derives that.
 */
class SharedAssignment
{
    /** The registry already serves the own package; a shared one of that name is arriving. */
    private const ALREADY_SERVED = 'Diese Registry führt bereits ein eigenes Paket mit demselben Namen: %s. '
        .'Ein geteiltes Paket darf ein eigenes nicht verdecken.';

    /** Both sides of the collision arrive in one submission. */
    private const SUBMITTED_TOGETHER = 'Diese Auswahl enthält ein eigenes und ein geteiltes Paket mit demselben '
        .'Namen: %s. Ein geteiltes Paket darf ein eigenes nicht verdecken.';

    /** The reverse: the registry already serves the shared package, an own one is arriving. */
    private const SHARED_ALREADY_SERVED = 'Diese Registry führt bereits ein geteiltes Paket mit demselben Namen: %s. '
        .'Entfernen Sie es zuerst aus dieser Registry, bevor Sie ein eigenes Paket unter diesem Namen zuweisen.';

    /**
     * Neither side arrived with this request, so the registry was already in the conflict
     * state. Unreachable through the application — every write that could produce it is
     * refused above — but stated rather than guessed at, and worded without claiming which
     * side is the new one, because with neither arriving there is no new one.
     */
    private const ALREADY_IN_CONFLICT = 'Diese Registry führt bereits ein eigenes und ein geteiltes Paket mit '
        .'demselben Namen: %s. Entfernen Sie eines der beiden aus dieser Registry.';

    /** A package being created would claim a name a shared assignment already serves. */
    private const NAME_HELD_BY_SHARED = 'Die Registry %s führt dieses Paket bereits als geteiltes Paket. '
        .'Entfernen Sie es dort zuerst, oder wählen Sie einen anderen Namen.';

    private const NAME_HELD_BY_SHARED_PLURAL = 'Folgende Registrys führen dieses Paket bereits als geteiltes '
        .'Paket: %s. Entfernen Sie es dort zuerst, oder wählen Sie einen anderen Namen.';

    /**
     * For a write that leaves the registry serving exactly the submission — `sync()`, and
     * registry creation, whose `sync()` runs against an empty registry.
     *
     * @param  array<int, string>  $packageIds
     *
     * @throws ValidationException keyed `package_ids`
     */
    public function assertReplacementAssignable(array $packageIds): void
    {
        $submitted = $this->packages($packageIds);

        $this->refuseShadowing($submitted, $submitted);
    }

    /**
     * For a write that adds to what the registry already serves — `syncWithoutDetaching()`.
     *
     * @param  array<int, string>  $packageIds
     *
     * @throws ValidationException keyed `package_ids`
     */
    public function assertAssignable(Group $group, array $packageIds): void
    {
        $submitted = $this->packages($packageIds);

        // assignedPackages(), not packages(): an assignment past its `available_until` is
        // not part of what the registry serves — RegistryAccessService reads it through the
        // same relation — so it cannot be shadowed and must not block anything. Applied to
        // the existing assignment only; the submission has no pivot row to expire yet.
        //
        // Columns qualified because the relation query joins `group_package`. A package
        // that is both assigned and submitted appears twice in the union; it is not
        // de-duplicated, because the predicate below asks whether a name carries a shared
        // AND a non-shared row, and a duplicate contributes the same `shared` value twice.
        // A de-duplication here was tried and removed: no mutation of it could be made to
        // fail a test, which is what it means for a line to be doing nothing.
        $assigned = $group->assignedPackages()
            ->get(['packages.id', 'packages.type', 'packages.name', 'packages.shared']);

        $this->refuseShadowing($assigned->concat($submitted), $submitted);
    }

    /**
     * The mirror direction, for the package-creation paths: a package about to be created
     * must not claim a name that one of the registries it is being attached to already
     * serves through a shared package. Same end state as every case above — one registry
     * serving a shared and an own package under one name — reached from the other side,
     * where there is no `package_ids` submission to state the rule over.
     *
     * Asked before the insert, so a refusal leaves no orphan package behind.
     *
     * @param  array<int, string>  $groupIds
     *
     * @throws ValidationException keyed `name`
     */
    public function assertNameUnclaimedIn(array $groupIds, PackageType $type, string $name): void
    {
        if ($groupIds === []) {
            return;
        }

        // assignedPackages(), for the same reason as above: an expired shared assignment
        // serves nothing, so it holds no name.
        $holders = Group::whereIn('id', $groupIds)
            ->whereHas('assignedPackages', fn ($q) => $q
                ->where('packages.shared', true)
                ->where('packages.type', $type)
                ->where('packages.name', $name))
            ->orderBy('name')
            ->pluck('name');

        if ($holders->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'name' => sprintf(
                $holders->count() === 1 ? self::NAME_HELD_BY_SHARED : self::NAME_HELD_BY_SHARED_PLURAL,
                $holders->implode(', '),
            ),
        ]);
    }

    /**
     * @param  array<int, string>  $packageIds
     * @return Collection<int, Package>
     */
    private function packages(array $packageIds): Collection
    {
        if ($packageIds === []) {
            return new Collection;
        }

        return Package::whereIn('id', $packageIds)->get(['id', 'type', 'name', 'shared']);
    }

    /**
     * The one invariant: within the set the registry would serve, no shared package may
     * carry the same type and name as a non-shared one.
     *
     * `$arriving` decides only which message explains the refusal — the refusal itself is
     * a property of `$resulting` alone.
     *
     * @param  Collection<int, Package>  $resulting  what the registry would serve afterwards
     * @param  Collection<int, Package>  $arriving  the submitted packages
     *
     * @throws ValidationException
     */
    private function refuseShadowing(Collection $resulting, Collection $arriving): void
    {
        $arrivingIds = $arriving->modelKeys();

        /** @var array<string, array<int, string>> $namesByMessage */
        $namesByMessage = [];

        foreach ($resulting->groupBy(fn (Package $p) => "{$p->type->value} {$p->name}") as $name => $sameName) {
            $shared = $sameName->where('shared', true);
            $own = $sameName->where('shared', false);

            if ($shared->isEmpty() || $own->isEmpty()) {
                continue;
            }

            $namesByMessage[$this->explain($shared, $own, $arrivingIds)][] = (string) $name;
        }

        if ($namesByMessage === []) {
            return;
        }

        throw ValidationException::withMessages([
            'package_ids' => array_map(
                fn (string $message, array $names) => sprintf($message, implode(', ', $names)),
                array_keys($namesByMessage),
                $namesByMessage,
            ),
        ]);
    }

    /**
     * Which side of the collision the operator submitted, and therefore what to tell them
     * to do about it.
     *
     * @param  Collection<int, Package>  $shared
     * @param  Collection<int, Package>  $own
     * @param  array<int, mixed>  $arrivingIds
     */
    private function explain(Collection $shared, Collection $own, array $arrivingIds): string
    {
        $sharedArriving = $shared->contains(fn (Package $p) => in_array($p->getKey(), $arrivingIds, true));
        $ownArriving = $own->contains(fn (Package $p) => in_array($p->getKey(), $arrivingIds, true));

        return match (true) {
            $sharedArriving && $ownArriving => self::SUBMITTED_TOGETHER,
            $sharedArriving => self::ALREADY_SERVED,
            $ownArriving => self::SHARED_ALREADY_SERVED,
            // Neither arrived: the registry was already in this state before the request,
            // which no write reachable from the application can produce. Refused anyway,
            // and worded so it does not tell the operator they just did something.
            default => self::ALREADY_IN_CONFLICT,
        };
    }
}

<?php

namespace App\Services\Package;

use App\Models\Group;
use App\Models\Package;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * A customer's own package always wins over a shared one of the same name, so a shared
 * assignment that would collide is refused rather than accepted and quietly shadowed.
 *
 * Same position the rest of this codebase takes at every boundary where two answers are
 * possible — the ownership migration, the legacy slug redirect: a wrong answer is worse
 * than an error. A customer must never be served the operator's package under a name they
 * own themselves.
 *
 * A registry can come to serve the name in two ways, and both shadow identically: it
 * already serves an own package under it, or the very submission that brings the shared
 * package brings the own one along. Only the first is visible in the registry's current
 * contents, so a check that asked the pivot alone would enforce the invariant for the
 * second request and not the first — and GroupController::store() seeds the pivot too, where the
 * registry has no contents at all and only the submission can reveal the collision.
 * Hence two entry points over one rule, differing in what they can compare against.
 *
 * `shared === true` already implies operator ownership: only an operator-organization
 * package can be marked shared (see Admin\PackageController::shared), so nothing here
 * re-derives that.
 */
class SharedAssignment
{
    private const ALREADY_SERVED = 'Diese Registry führt bereits ein eigenes Paket mit demselben Namen: %s. '
        .'Ein geteiltes Paket darf ein eigenes nicht verdecken.';

    private const SUBMITTED_TOGETHER = 'Diese Auswahl enthält ein eigenes und ein geteiltes Paket mit demselben '
        .'Namen: %s. Ein geteiltes Paket darf ein eigenes nicht verdecken.';

    /**
     * The rule for a registry that exists: its current contents and the submission both
     * count towards what it would serve.
     *
     * @param  array<int, string>  $packageIds
     *
     * @throws ValidationException keyed `package_ids`
     */
    public function assertAssignable(Group $group, array $packageIds): void
    {
        $submitted = $this->submitted($packageIds);
        $shared = $submitted->where('shared', true);

        if ($shared->isEmpty()) {
            return;
        }

        $this->refuseCollisions(
            $shared,
            // Columns qualified because the relation query joins `group_package`.
            $group->packages()->where('packages.shared', false)->get(['packages.type', 'packages.name']),
            self::ALREADY_SERVED,
        );

        $this->refuseCollisions($shared, $submitted->where('shared', false), self::SUBMITTED_TOGETHER);
    }

    /**
     * The same rule for a registry being created, which has no contents yet — so the
     * submission is the whole of what it would serve.
     *
     * Stated separately rather than run after the insert, because the refusal must not
     * leave a half-created registry behind.
     *
     * @param  array<int, string>  $packageIds
     *
     * @throws ValidationException keyed `package_ids`
     */
    public function assertCreatable(array $packageIds): void
    {
        $submitted = $this->submitted($packageIds);
        $shared = $submitted->where('shared', true);

        if ($shared->isEmpty()) {
            return;
        }

        $this->refuseCollisions($shared, $submitted->where('shared', false), self::SUBMITTED_TOGETHER);
    }

    /**
     * @param  array<int, string>  $packageIds
     * @return Collection<int, Package>
     */
    private function submitted(array $packageIds): Collection
    {
        if ($packageIds === []) {
            return new Collection;
        }

        return Package::whereIn('id', $packageIds)->get(['id', 'type', 'name', 'shared']);
    }

    /**
     * @param  Collection<int, Package>  $shared  the shared packages being assigned
     * @param  Collection<int, Package>  $own  the non-shared packages they would stand beside
     * @param  string  $message  sprintf template taking the colliding names
     *
     * @throws ValidationException
     */
    private function refuseCollisions(Collection $shared, Collection $own, string $message): void
    {
        $conflicts = $own->filter(fn (Package $p) => $shared->contains(
            fn (Package $in) => $in->type === $p->type && $in->name === $p->name,
        ));

        if ($conflicts->isEmpty()) {
            return;
        }

        $names = $conflicts->map(fn (Package $p) => "{$p->type->value} {$p->name}")->unique()->implode(', ');

        throw ValidationException::withMessages([
            'package_ids' => sprintf($message, $names),
        ]);
    }
}

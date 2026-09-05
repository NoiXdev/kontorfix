<?php

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory, HasUuids, LogsActivity;

    /**
     * A rename gives up the registry's frozen legacy address.
     *
     * `legacy_slug` is the one-segment /r/{slug} URL this registry answered before the
     * instance was upgraded to organization-scoped slugs; it was frozen at migration time
     * (2026_09_03_100000) and is never written by the application. Keeping it across a
     * rename would hand the registry a permanent alias, which is exactly the opposite of
     * the documented decision that changing a slug moves the address with no alias left
     * behind — and it would keep a name the operator has given up reserved instance-wide
     * against every other tenant.
     *
     * Lives on the model rather than in the two update paths (console and JSON API): the
     * column is an invariant of the row, not a concern of whoever happens to write it.
     *
     * This is also what keeps a non-NULL `legacy_slug` always equal to the row's own live
     * `slug`: it is only ever frozen from `slug` (at migration time) and only ever cleared,
     * never independently changed, so the two can never drift apart while `legacy_slug` is
     * set. That equality is load-bearing for `App\Rules\UnclaimedSlug`, which checks
     * `groups.slug` and never `legacy_slug` directly — a frozen legacy address is still
     * covered, because it can only ever equal the `slug` of the very row that is already
     * being checked.
     */
    protected static function booted(): void
    {
        static::updating(function (self $group): void {
            if ($group->isDirty('slug')) {
                $group->legacy_slug = null;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('registry')
            ->logOnly(['name', 'slug', 'public', 'portal_enabled', 'organization_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * `portal_enabled` here answers "does this registry appear in its organization's
     * portal" — off hides the card and leaves this registry's own /r/... endpoints serving
     * exactly as before. It is NOT `organizations.portal_enabled`, which answers "does this
     * customer have a portal at all" and makes /c/{slug} a 404 outright. The two compose and
     * neither is derived from the other: an open portal can show no registry at all, and a
     * closed one hides registries that are individually switched on.
     */
    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'public',
        'portal_enabled',
    ];

    protected $attributes = [
        'portal_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'public' => 'bool',
            'portal_enabled' => 'bool',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<Package, $this, GroupPackage>
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class)
            ->using(GroupPackage::class)
            ->withPivot('version_constraint', 'available_until');
    }

    /**
     * The assignments that are actually in force: `available_until` makes an assignment
     * time-limited, and an expired row serves nothing.
     *
     * The one statement of that predicate. RegistryAccessService decides what a registry
     * serves with it, App\Services\Package\SharedAssignment decides whether a name is
     * already taken with it, and App\Http\Controllers\Registry\PypiController decides
     * through it which project a twine upload may target — questions that must never be able
     * to disagree about whether a given row counts.
     *
     * `available_until` HAS EXACTLY ONE WRITER: Admin\GroupController::updateAssignment(),
     * the assignment dialog's date field (spec §6). Everything else only reads the column.
     *
     * That writer asks SharedAssignment before it writes, and it has to. SharedAssignment's
     * tolerance of expired rows — it permits assigning or creating a customer's own package
     * under a name only a lapsed shared assignment used to serve — is safe only while an
     * expired assignment stays expired. Pushing one back into the future reverses that
     * decision retroactively and reaches the collision the attach guard refuses, through a
     * request that changes no pivot membership at all and so passes none of the six
     * membership writers' guards. Extending an assignment is an assignment.
     *
     * ANY FURTHER WRITER OF THIS COLUMN MUST DO THE SAME. The reasoning above is a property
     * of the column, not of the controller that happens to hold the form today.
     *
     * Separately, and NOT an entry point of that guard — the counts above are about
     * SharedAssignment's three, and these add none — this relation has *dependents* that a
     * date pushed back into the future reopens:
     *   - PypiController::upload() resolves its target project through this relation and
     *     through nothing else, never reaching canAccessPackage(), so it is a publish path
     *     and not only a read path. npm's equivalent reaches the same relation through
     *     RegistryAccessService::packageBelongsToGroup().
     *   - Admin\GroupController::show() reports per assignment whether the registry serves
     *     it, decided here rather than by comparing dates in the payload or the browser: a
     *     second statement of the predicate could disagree with what the registry does, and
     *     the console disagreeing with the registry about exactly this is what made an
     *     expired assignment look live on that page.
     *
     * @return BelongsToMany<Package, $this, GroupPackage>
     */
    public function assignedPackages(): BelongsToMany
    {
        return $this->packages()->where(fn (Builder $q) => $q
            ->whereNull('group_package.available_until')
            ->orWhere('group_package.available_until', '>', now()));
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * @return HasMany<RegistryToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(RegistryToken::class);
    }

    /**
     * @return HasMany<Upstream, $this>
     */
    public function upstreams(): HasMany
    {
        return $this->hasMany(Upstream::class);
    }
}

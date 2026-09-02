<?php

namespace Database\Factories;

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Enums\SyncStatus;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // A package is owned by exactly one organization. The factory mints its own by
            // default so a bare Package::factory() row is valid; pair it with a registry
            // through inOrgOf(), because GroupFactory mints an organization of its own too
            // and the two would otherwise disagree.
            'organization_id' => Organization::factory(),
            'type' => PackageType::Composer,
            // Derived from the type exactly as both create paths do, so a factory row is
            // as truthful as a real one. Override for a Python git mirror.
            'source_mode' => fn (array $attributes): PackageSourceMode => PackageSourceMode::defaultFor(
                $attributes['type'] instanceof PackageType
                    ? $attributes['type']
                    : PackageType::from((string) $attributes['type']),
            ),
            'name' => Str::lower(fake()->word().'-'.fake()->unique()->numberBetween(1, 99999).'/'.fake()->word()),
            'description' => fake()->sentence(),
            'repository_url' => null,
            'sync_status' => SyncStatus::Pending,
        ];
    }

    /** Own the package where the given registry is owned — the pairing every attach needs. */
    public function inOrgOf(Group $group): static
    {
        return $this->state(fn (): array => ['organization_id' => $group->organization_id]);
    }
}

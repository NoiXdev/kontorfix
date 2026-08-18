<?php

namespace Database\Factories;

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Enums\SyncStatus;
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
}

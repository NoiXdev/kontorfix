<?php

namespace Database\Factories;

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
            'name' => Str::lower(fake()->word().'-'.fake()->unique()->numberBetween(1, 99999).'/'.fake()->word()),
            'description' => fake()->sentence(),
            'repository_url' => null,
            'sync_status' => SyncStatus::Pending,
        ];
    }
}

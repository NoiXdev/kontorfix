<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageVersion>
 */
class PackageVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'version' => '1.0.0.0',
            'version_pretty' => 'v1.0.0',
            'source_reference' => fake()->sha1(),
            'metadata' => ['name' => 'acme/demo', 'require' => []],
            'dist_path' => null,
            'released_at' => now(),
        ];
    }
}

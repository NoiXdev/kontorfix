<?php

namespace Database\Factories;

use App\Models\Upstream;
use App\Models\UpstreamAllowedPackage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UpstreamAllowedPackage>
 */
class UpstreamAllowedPackageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'upstream_id' => Upstream::factory(),
            'name' => Str::lower(fake()->word().'/'.fake()->unique()->word()),
        ];
    }
}

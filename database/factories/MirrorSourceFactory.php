<?php

namespace Database\Factories;

use App\Enums\PackageType;
use App\Models\MirrorSource;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MirrorSource>
 */
class MirrorSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'type' => PackageType::Composer,
            'url' => 'https://repo.example.test',
            'auth_token' => null,
        ];
    }
}

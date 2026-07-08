<?php

namespace Database\Factories;

use App\Enums\TokenAbility;
use App\Models\Organization;
use App\Models\RegistryToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RegistryToken>
 */
class RegistryTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'group_id' => null,
            'name' => fake()->word(),
            'token_hash' => hash('sha256', Str::random(40)),
            'ability' => TokenAbility::Read,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'hostname' => 'packages.'.fake()->unique()->domainName(),
        ];
    }
}

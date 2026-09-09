<?php

namespace Database\Factories;

use App\Models\OciBlob;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OciBlob>
 */
class OciBlobFactory extends Factory
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
            'digest' => 'sha256:'.hash('sha256', Str::random()),
            'size' => fake()->numberBetween(1024, 50 * 1024 * 1024),
            'path' => 'blobs/'.Str::random(40),
        ];
    }
}

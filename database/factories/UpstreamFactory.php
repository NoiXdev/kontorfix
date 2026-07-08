<?php

namespace Database\Factories;

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\Group;
use App\Models\Upstream;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Upstream>
 */
class UpstreamFactory extends Factory
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
            'type' => PackageType::Composer,
            'url' => 'https://repo.'.fake()->unique()->domainWord().'.test',
            'policy' => UpstreamPolicy::Proxy,
            'auth_token' => null,
            'priority' => 0,
            'enabled' => true,
        ];
    }
}

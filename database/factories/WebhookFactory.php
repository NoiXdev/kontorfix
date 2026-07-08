<?php

namespace Database\Factories;

use App\Enums\WebhookEvent;
use App\Models\Organization;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
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
            'url' => 'https://hooks.'.fake()->unique()->domainWord().'.test/kfx',
            'secret' => 'sec',
            'events' => [WebhookEvent::PackageSynced->value],
            'enabled' => true,
        ];
    }
}

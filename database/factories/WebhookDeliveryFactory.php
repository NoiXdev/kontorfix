<?php

namespace Database\Factories;

use App\Enums\WebhookEvent;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_id' => Webhook::factory(),
            'event' => WebhookEvent::PackageSynced->value,
            'payload' => ['x' => 1],
            'success' => false,
            'attempts' => 0,
        ];
    }
}

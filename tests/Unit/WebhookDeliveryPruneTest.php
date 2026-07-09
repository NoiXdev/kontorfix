<?php

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prunes webhook deliveries older than 30 days', function () {
    $webhook = Webhook::factory()->create();
    $old = WebhookDelivery::factory()->for($webhook)->create();
    $old->forceFill(['created_at' => now()->subDays(40)])->save();
    $recent = WebhookDelivery::factory()->for($webhook)->create();
    $recent->forceFill(['created_at' => now()->subDays(5)])->save();

    $this->artisan('model:prune', ['--model' => [WebhookDelivery::class]])->assertSuccessful();

    expect(WebhookDelivery::find($old->id))->toBeNull();
    expect(WebhookDelivery::find($recent->id))->not->toBeNull();
});

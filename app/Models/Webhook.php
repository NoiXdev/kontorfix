<?php

namespace App\Models;

use App\Enums\WebhookEvent;
use Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'url',
        'secret',
        'events',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'enabled' => 'bool',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribesTo(WebhookEvent $event): bool
    {
        return in_array($event->value, $this->events ?? [], true);
    }
}

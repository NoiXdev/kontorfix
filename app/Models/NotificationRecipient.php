<?php

namespace App\Models;

use App\Enums\NotificationEvent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationRecipient extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'email', 'name', 'events', 'enabled'];

    protected function casts(): array
    {
        return ['events' => 'array', 'enabled' => 'bool'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Mirrors Webhook::subscribesTo() — a null list means no subscription, not all of them. */
    public function subscribesTo(NotificationEvent $event): bool
    {
        return in_array($event->value, $this->events ?? [], true);
    }
}

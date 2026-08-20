<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery of one recorded event to one recipient — the fact SendNotificationDigest
 * checks before mailing and writes after a send succeeds.
 *
 * Delivery is deliberately a fact about the (event, recipient) pair rather than about the
 * event alone: that is what stops one recipient's mail failure from silently costing a
 * different, successfully-mailed recipient their copy. See the per-recipient-delivery
 * brief in .superpowers/sdd/ for the defect this table replaces.
 */
class NotificationEventDelivery extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['notification_event_id', 'notification_recipient_id', 'delivered_at'];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<NotificationEventRecord, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(NotificationEventRecord::class, 'notification_event_id');
    }

    /**
     * @return BelongsTo<NotificationRecipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'notification_recipient_id');
    }
}

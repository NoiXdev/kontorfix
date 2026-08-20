<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A failure worth mailing about, captured when it happened.
 *
 * Recorded rather than re-derived at digest time because the source is overwritten: the
 * next sync attempt replaces `packages.sync_error`, so by the time an hourly job looks,
 * the message that would have been reported is gone.
 */
class NotificationEventRecord extends Model
{
    use HasUuids, MassPrunable;

    protected $table = 'notification_events';

    protected $fillable = [
        'organization_id', 'type', 'subject_type', 'subject_id',
        'subject_label', 'summary', 'occurred_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'notified_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Only reported rows are pruned. An unreported row is a failure nobody has been told
     * about — usually because no recipient subscribes to its type yet — and deleting it
     * would silently discard the backlog that appears the moment someone does subscribe.
     *
     * @return Builder<NotificationEventRecord>
     */
    public function prunable(): Builder
    {
        return static::whereNotNull('notified_at')->where('notified_at', '<', now()->subDays(30));
    }
}

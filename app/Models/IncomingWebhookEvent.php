<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $incoming_webhook_id
 * @property string $provider
 * @property string|null $repo_url
 * @property bool $signature_valid
 * @property int $matched_packages
 * @property int|null $status_code
 * @property string|null $ip
 * @property array<string,mixed>|null $payload
 */
class IncomingWebhookEvent extends Model
{
    use HasUuids;

    /** Most-recent incoming events to retain — older ones are pruned on write. */
    public const RETENTION = 250;

    /** @var list<string> */
    protected $fillable = [
        'incoming_webhook_id',
        'provider',
        'repo_url',
        'signature_valid',
        'matched_packages',
        'status_code',
        'ip',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signature_valid' => 'bool',
            'matched_packages' => 'int',
            'status_code' => 'int',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<IncomingWebhook, $this>
     */
    public function incomingWebhook(): BelongsTo
    {
        return $this->belongsTo(IncomingWebhook::class);
    }

    /**
     * Records an event and trims the table back to the retention limit, so the audit
     * keeps only the most recent N deliveries (debugging aid, not long-term storage).
     *
     * @param  array<string,mixed>  $attributes
     */
    public static function record(array $attributes): self
    {
        $event = static::create($attributes);

        // Keep the newest N rows and drop the rest. Selecting the ids to keep (rather
        // than a created_at cutoff) stays correct even when many rows share the same
        // timestamp, which happens under test and under bursty real traffic.
        $keepIds = static::query()->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::RETENTION)->pluck('id');

        if ($keepIds->count() >= self::RETENTION) {
            static::query()->whereNotIn('id', $keepIds)->delete();
        }

        return $event;
    }
}

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

    /** Most-recent verified deliveries to retain — older ones are pruned on write. */
    public const RETENTION = 250;

    /**
     * Retention for deliveries that failed signature verification. They are pruned in
     * their own partition, so a flood of them cannot displace a verified delivery.
     */
    public const REJECTED_RETENTION = 50;

    /** Upper bound on the JSON-encoded payload kept per event. */
    public const MAX_PAYLOAD_BYTES = 128 * 1024;

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
     * Records an event and trims the audit back to its retention limits (debugging aid,
     * not long-term storage).
     *
     * Verified and rejected deliveries are pruned as two independent rings. Recording
     * before verification is deliberate — a failed signature check is not logged anywhere
     * else in the application — but under one shared ring anybody holding a hook URL
     * without its secret could post a few hundred unsigned requests and evict every
     * genuine delivery, erasing the evidence of the attack as it happened. Split, a flood
     * of garbage can only roll over other garbage.
     *
     * @param  array<string,mixed>  $attributes
     */
    public static function record(array $attributes): self
    {
        $event = static::create(self::withBoundedPayload($attributes));

        // Only the partition just written to can have grown.
        $event->signature_valid
            ? self::prunePartition(true, self::RETENTION)
            : self::prunePartition(false, self::REJECTED_RETENTION);

        return $event;
    }

    /**
     * The payload is stored verbatim for inspection and comes from an unauthenticated
     * request body, so it is capped — otherwise the same flood that cannot evict rows
     * any more could still bloat the table with oversized bodies.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private static function withBoundedPayload(array $attributes): array
    {
        if (! isset($attributes['payload']) || ! is_array($attributes['payload'])) {
            return $attributes;
        }

        $encoded = json_encode($attributes['payload']);
        if (is_string($encoded) && strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            $attributes['payload'] = ['_truncated' => true, '_original_bytes' => strlen($encoded)];
        }

        return $attributes;
    }

    /**
     * Keep the newest N rows of one partition and drop the rest. Selecting the ids to
     * keep (rather than a created_at cutoff) stays correct even when many rows share the
     * same timestamp, which happens under test and under bursty real traffic.
     */
    private static function prunePartition(bool $signatureValid, int $retention): void
    {
        $keepIds = static::query()->where('signature_valid', $signatureValid)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($retention)->pluck('id');

        if ($keepIds->count() >= $retention) {
            static::query()->where('signature_valid', $signatureValid)
                ->whereNotIn('id', $keepIds)->delete();
        }
    }
}

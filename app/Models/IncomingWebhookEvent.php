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

    /** Most-recent verified deliveries to retain per hook — older ones are pruned on write. */
    public const RETENTION = 250;

    /**
     * Retention for deliveries that failed signature verification. They are pruned in
     * their own partition, so a flood of them cannot displace a verified delivery.
     */
    public const REJECTED_RETENTION = 50;

    /** Upper bound on the JSON-encoded payload kept per event. */
    public const MAX_PAYLOAD_BYTES = 128 * 1024;

    /** How much of an oversized payload is still kept, so the record is not empty. */
    public const PAYLOAD_EXCERPT_BYTES = 8 * 1024;

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
     * The ring is partitioned by hook AND by verification outcome. Recording before
     * verification is deliberate — a failed signature check is not logged anywhere else in
     * the application — but the partitions are what make the record survive being attacked:
     *
     *  - splitting valid from rejected stops a flood of unsigned requests from evicting the
     *    genuine deliveries of the hook it targets;
     *  - splitting by hook stops it from evicting anything belonging to a *different* hook.
     *    Without that, a hook URL is a lever against every other hook's evidence on the
     *    instance — and the rejected cap being the smaller of the two made it cheap: 50
     *    unsigned posts to hook A used to erase A's, B's and C's rejected records alike.
     *
     * A flood can now only roll over its own hook's own garbage. The legacy shared endpoint
     * has no hook row and forms its own partition, which is correct: it is one endpoint.
     *
     * @param  array<string,mixed>  $attributes
     */
    public static function record(array $attributes): self
    {
        $event = static::create(self::withBoundedPayload($attributes));

        // Only the partition just written to can have grown.
        $event->signature_valid
            ? self::prunePartition($event->incoming_webhook_id, true, self::RETENTION)
            : self::prunePartition($event->incoming_webhook_id, false, self::REJECTED_RETENTION);

        return $event;
    }

    /**
     * The payload is stored verbatim for inspection and comes from an unauthenticated
     * request body, so it is capped — otherwise the same flood that cannot evict rows
     * any more could still bloat the table with oversized bodies.
     *
     * Over the cap the record keeps an excerpt rather than nothing. Replacing the whole
     * body with a marker made the cap destroy exactly the record it was protecting: a
     * legitimate push with a large body — a monorepo commit listing hundreds of files —
     * left an audit row that said only "there was something here".
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
            $attributes['payload'] = [
                '_truncated' => true,
                '_original_bytes' => strlen($encoded),
                '_excerpt' => substr($encoded, 0, self::PAYLOAD_EXCERPT_BYTES),
            ];
        }

        return $attributes;
    }

    /**
     * Keep the newest N rows of one partition and drop the rest. Selecting the ids to
     * keep (rather than a created_at cutoff) stays correct even when many rows share the
     * same timestamp, which happens under test and under bursty real traffic.
     */
    private static function prunePartition(?string $hookId, bool $signatureValid, int $retention): void
    {
        // One hook's rows of one verification outcome. A null hook id is the legacy shared
        // endpoint and is matched with `IS NULL` — never with `= null`, which matches
        // nothing and would silently disable the prune for that partition.
        $partition = fn () => static::query()
            ->where('signature_valid', $signatureValid)
            ->when(
                $hookId === null,
                fn ($q) => $q->whereNull('incoming_webhook_id'),
                fn ($q) => $q->where('incoming_webhook_id', $hookId),
            );

        $keepIds = $partition()
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($retention)->pluck('id');

        if ($keepIds->count() >= $retention) {
            $partition()->whereNotIn('id', $keepIds)->delete();
        }
    }
}

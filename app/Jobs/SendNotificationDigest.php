<?php

namespace App\Jobs;

use App\Enums\NotificationEvent;
use App\Mail\FailureDigest;
use App\Models\NotificationEventDelivery;
use App\Models\NotificationEventRecord;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Support\DigestSummary;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendNotificationDigest implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A safety net for the case the lock's normal release (end of handle()) never runs —
     * the worker is killed rather than finishing. Comfortably above the 60s job timeout
     * (config/horizon.php) so a live run is never pre-empted by its own stale lock.
     */
    public int $uniqueFor = 300;

    /**
     * Rows are pulled in batches of this size per organization per run, oldest first, so a
     * backlog large enough to blow PostgreSQL's ~65k bind-parameter limit on the trailing
     * `whereIn` update (see the chunking below) is worked down over several runs instead of
     * being loaded — and attempted as one UPDATE — in a single `->get()`.
     */
    private const MAX_PENDING_PER_DIGEST = 1000;

    public function handle(): void
    {
        // Captured once and reused as the due-mark for every organization in this run,
        // instead of now() taken again after sending. last_digest_sent_at otherwise
        // records when the run *finished*, not when it *started*; the next run's isDue()
        // compares against its own start time, so the gap it sees is the cadence *minus*
        // however long the previous run's synchronous mail sends took. That is a
        // deterministic, ever-growing drift, not flakiness (see docs/development.md).
        $startedAt = now();

        Organization::query()
            ->whereNot('notification_cadence', 'off')
            ->each(function (Organization $organization) use ($startedAt): void {
                if (! $this->isDue($organization)) {
                    return;
                }

                $this->digestFor($organization, $startedAt);
            });
    }

    private function isDue(Organization $organization): bool
    {
        $last = $organization->last_digest_sent_at;

        if ($last === null) {
            return true;
        }

        return match ($organization->notification_cadence) {
            'daily' => $last->lessThanOrEqualTo(now()->subDay()),
            default => $last->lessThanOrEqualTo(now()->subHour()),
        };
    }

    private function digestFor(Organization $organization, Carbon $startedAt): void
    {
        // Checked before the pending query runs, not after: an organization with no
        // enabled recipient can never deliver anything (nothing is mailed, so no delivery
        // row is ever written), which would otherwise mean loading its entire, ever-growing
        // pending backlog into memory every single run for no reason.
        if ($organization->recipients()->where('enabled', true)->doesntExist()) {
            return;
        }

        // Still the bounded, org-wide candidate set: notified_at is null for any event
        // that is not yet fully delivered to every currently-enabled subscribed recipient,
        // so it remains the right "is there anything at all to look at" gate and the right
        // place to cap memory — it is who still needs *their* copy that changed, not which
        // events are in play.
        $pending = NotificationEventRecord::query()
            ->where('organization_id', $organization->id)
            ->whereNull('notified_at')
            ->orderBy('occurred_at')
            // occurred_at is timestamp(0) (second resolution) — see DigestSummary's
            // docblock. A secondary key makes the fetch order deterministic instead of
            // leaving same-second ties to whatever order PostgreSQL happens to return.
            ->orderBy('id')
            ->limit(self::MAX_PENDING_PER_DIGEST)
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $recipients = $organization->recipients()->where('enabled', true)->get();

        // notification_event_id => list of notification_recipient_id already delivered.
        // Kept up to date as the loop below writes new delivery rows, so the completion
        // check after the loop sees this run's deliveries too, not just prior runs'.
        $deliveredTo = NotificationEventDelivery::query()
            ->whereIn('notification_event_id', $pending->pluck('id'))
            ->get(['notification_event_id', 'notification_recipient_id'])
            ->groupBy('notification_event_id')
            ->map(fn ($rows) => $rows->pluck('notification_recipient_id')->all())
            ->all();

        $sentAny = false;

        foreach ($recipients as $recipient) {
            // Per-recipient pending set: this recipient's subscribed events that this
            // recipient specifically has no delivery row for yet — not the org-wide
            // whereNull('notified_at') set every recipient used to share. That sharing is
            // exactly what let one recipient's failed send cost a different, successfully
            // mailed recipient their copy (see the per-recipient-delivery brief).
            $theirs = $pending->filter(
                fn (NotificationEventRecord $e): bool => ($type = NotificationEvent::tryFrom($e->type)) !== null
                    && $recipient->subscribesTo($type)
                    && ! in_array($recipient->id, $deliveredTo[$e->id] ?? [], true),
            );

            if ($theirs->isEmpty()) {
                continue;
            }

            try {
                Mail::to($recipient->email)->send(new FailureDigest($organization, DigestSummary::fold($theirs)));
            } catch (Throwable $e) {
                // One recipient's mailbox rejecting delivery (an SMTP 550, a timeout, ...)
                // must not stop the rest of the loop: the recipients before this one have
                // already been mailed, and the ones after it still deserve their attempt.
                // No delivery row is written for them, so a later run retries just their
                // copy rather than silently swallowing a backlog nobody was ever told about.
                Log::error('Failed to send failure digest', [
                    'organization_id' => $organization->id,
                    'recipient' => $recipient->email,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $sentAny = true;
            $deliveredAt = now();

            // Written only after the send returned, one row per (event, recipient) pair.
            $rows = $theirs->map(fn (NotificationEventRecord $event): array => [
                'id' => (string) (new NotificationEventDelivery)->newUniqueId(),
                'notification_event_id' => $event->id,
                'notification_recipient_id' => $recipient->id,
                'delivered_at' => $deliveredAt,
            ])->all();

            try {
                // This DB::transaction() wrapper is not decoration — do not remove it as
                // "redundant" just because a single insert doesn't look like it needs one.
                // On PostgreSQL, catching the QueryException below is not enough by itself:
                // once one statement inside an open transaction fails, PostgreSQL marks that
                // *entire* transaction aborted and refuses every further statement ("current
                // transaction is aborted, commands ignored until end of transaction block")
                // until it is rolled back — a plain try/catch around the insert does not
                // undo that. Whenever this job runs inside an ambient transaction it did not
                // open itself — RefreshDatabase wraps every test in exactly such a
                // transaction, and any future caller could run handle() inside one of its
                // own — that would poison every query for the rest of the run, including the
                // very next recipient's insert and the org's last_digest_sent_at update, the
                // instant one recipient's insert fails. Wrapping the insert in its own nested
                // DB::transaction() gives PostgreSQL a SAVEPOINT to roll back to instead (via
                // Laravel's automatic savepoint support for nested transactions), so only
                // this recipient's insert is undone and the surrounding transaction — ours or
                // an ambient one — stays healthy for everything that runs after it.
                DB::transaction(function () use ($rows): void {
                    // Chunked well under PostgreSQL's ~65535 bind-parameter limit,
                    // matching the chunking below: $theirs is bounded by $pending (at
                    // most MAX_PENDING_PER_DIGEST rows), but a larger cap in the future
                    // should not have to remember to also raise this number.
                    foreach (array_chunk($rows, 1000) as $chunk) {
                        NotificationEventDelivery::insert($chunk);
                    }
                });
            } catch (Throwable $e) {
                // Mirrors the send's own isolation above: a unique violation from a
                // concurrent run (ShouldBeUnique's lock is best-effort — $uniqueFor is a
                // safety net, not a guarantee — and handle() is directly callable) or a
                // connection blip must not propagate out of digestFor() and abort every
                // organization not yet processed in this run. The mail has already gone
                // out, so failing to record the delivery here means the next run will not
                // see it as delivered and will mail this recipient the same digest again —
                // a duplicate mail, not a silently dropped notification. That is the safe
                // direction to fail in, so $deliveredTo is deliberately left unmarked below.
                Log::error('Failed to record failure digest delivery', [
                    'organization_id' => $organization->id,
                    'recipient' => $recipient->email,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($theirs as $event) {
                $deliveredTo[$event->id][] = $recipient->id;
            }
        }

        // notified_at flips to "fully delivered" only once every *currently enabled*
        // subscribed recipient has a delivery row — computed fresh each run rather than
        // cached, so a recipient disabled or deleted after a partial delivery cannot block
        // an event from ever being considered complete (see "Marking" in the brief).
        $subscribersByType = [];

        foreach ($pending->pluck('type')->unique() as $typeValue) {
            $type = NotificationEvent::tryFrom($typeValue);

            $subscribersByType[$typeValue] = $type === null
                ? []
                : $recipients->filter(fn (NotificationRecipient $r): bool => $r->subscribesTo($type))->pluck('id')->all();
        }

        $completed = [];

        foreach ($pending as $event) {
            $required = $subscribersByType[$event->type] ?? [];

            // Nobody currently enabled subscribes to this type: stays null forever, exactly
            // as before this table existed — an empty requirement is not a met one.
            if ($required === []) {
                continue;
            }

            if (array_diff($required, $deliveredTo[$event->id] ?? []) === []) {
                $completed[] = $event->id;
            }
        }

        if ($completed !== []) {
            // Chunked well under PostgreSQL's ~65535 bind-parameter limit: a single id per
            // parameter means an unchunked whereIn over a large backlog (packages:resync
            // runs hourly, so a persistent breakage accumulates rows fast) would eventually
            // make this UPDATE itself throw.
            foreach (array_chunk($completed, 1000) as $chunk) {
                NotificationEventRecord::whereIn('id', $chunk)->update(['notified_at' => now()]);
            }
        }

        // Stamped only when a send actually happened this run — a run that only completed
        // an event via a disabled recipient (no mail sent at all) must not count as due
        // work for isDue()'s purposes.
        if ($sentAny) {
            $organization->update(['last_digest_sent_at' => $startedAt]);
        }
    }
}

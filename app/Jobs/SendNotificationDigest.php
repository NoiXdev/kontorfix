<?php

namespace App\Jobs;

use App\Enums\NotificationEvent;
use App\Mail\FailureDigest;
use App\Models\NotificationEventRecord;
use App\Models\Organization;
use App\Support\DigestSummary;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
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
        // enabled recipient can never mark anything reported (nothing is mailed, so
        // nothing enters $reported), which would otherwise mean loading its entire,
        // ever-growing pending backlog into memory every single run for no reason.
        if ($organization->recipients()->where('enabled', true)->doesntExist()) {
            return;
        }

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
        $reported = [];

        foreach ($recipients as $recipient) {
            $theirs = $pending->filter(
                fn (NotificationEventRecord $e): bool => ($type = NotificationEvent::tryFrom($e->type)) !== null
                    && $recipient->subscribesTo($type),
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
                // Their rows stay unmarked and are retried on the next due run.
                Log::error('Failed to send failure digest', [
                    'organization_id' => $organization->id,
                    'recipient' => $recipient->email,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            // Marked only after the send returned. A recipient whose mailer throws leaves
            // their rows pending, so a later run retries them rather than silently
            // swallowing a backlog nobody was ever told about.
            foreach ($theirs as $event) {
                $reported[$event->id] = true;
            }
        }

        if ($reported !== []) {
            // Chunked well under PostgreSQL's ~65535 bind-parameter limit: a single id per
            // parameter means an unchunked whereIn over a large backlog (packages:resync
            // runs hourly, so a persistent breakage accumulates rows fast) would eventually
            // make this UPDATE itself throw.
            foreach (array_chunk(array_keys($reported), 1000) as $chunk) {
                NotificationEventRecord::whereIn('id', $chunk)->update(['notified_at' => now()]);
            }

            $organization->update(['last_digest_sent_at' => $startedAt]);
        }
    }
}

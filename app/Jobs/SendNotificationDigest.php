<?php

namespace App\Jobs;

use App\Enums\NotificationEvent;
use App\Mail\FailureDigest;
use App\Models\NotificationEventRecord;
use App\Models\Organization;
use App\Support\DigestSummary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendNotificationDigest implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Organization::query()
            ->whereNot('notification_cadence', 'off')
            ->each(function (Organization $organization): void {
                if (! $this->isDue($organization)) {
                    return;
                }

                $this->digestFor($organization);
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

    private function digestFor(Organization $organization): void
    {
        $pending = NotificationEventRecord::query()
            ->where('organization_id', $organization->id)
            ->whereNull('notified_at')
            ->orderBy('occurred_at')
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

            Mail::to($recipient->email)->send(new FailureDigest($organization, DigestSummary::fold($theirs)));

            // Marked only after the send returned. A mailer that throws leaves these rows
            // pending, so the next run retries them rather than silently swallowing a
            // backlog nobody was ever told about.
            foreach ($theirs as $event) {
                $reported[$event->id] = true;
            }
        }

        if ($reported !== []) {
            NotificationEventRecord::whereIn('id', array_keys($reported))->update(['notified_at' => now()]);
            $organization->update(['last_digest_sent_at' => now()]);
        }
    }
}

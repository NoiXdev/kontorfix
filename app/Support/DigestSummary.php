<?php

namespace App\Support;

use Carbon\CarbonInterface;

final readonly class DigestLine
{
    public function __construct(
        public string $type,
        public string $subjectLabel,
        public int $count,
        public string $latestSummary,
        public CarbonInterface $latestAt,
    ) {}
}

/**
 * Folds recorded events into the lines a digest mail shows.
 *
 * `packages:resync` runs hourly, so a repository that has been broken for a day produces
 * 24 identical failures. Listing them turns a daily digest into a wall of the same line,
 * which is how a notification people read becomes one they filter away. One line per
 * subject, with the count and the most recent message, keeps the mail worth opening.
 *
 * The grouping key is (type, subject): the same package failing to sync and failing to
 * reach a webhook are two different problems with two different fixes.
 *
 * Tie-breaking on identical `occurred_at` (plausible: `packages:resync` runs hourly) is
 * "newest first" by iteration order, not by a secondary key: within a group `>=` lets the
 * later-iterated event win the summary/timestamp, and across groups the equal-timestamp
 * lines keep the input's first-appearance order (PHP's `usort` is stable since 8.0).
 */
final class DigestSummary
{
    /**
     * @param  iterable<object{type: string, subject_label: string, summary: string, occurred_at: CarbonInterface}>  $events
     * @return list<DigestLine>
     */
    public static function fold(iterable $events): array
    {
        /** @var array<string, array{type: string, subject: string, count: int, summary: string, at: CarbonInterface}> $groups */
        $groups = [];

        foreach ($events as $event) {
            $key = $event->type."\0".$event->subject_label;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'type' => $event->type,
                    'subject' => $event->subject_label,
                    'count' => 0,
                    'summary' => $event->summary,
                    'at' => $event->occurred_at,
                ];
            }

            $groups[$key]['count']++;

            // Newest wins: the first message is usually the least useful one, and the
            // reader wants to know what is wrong now, not what was wrong first.
            if ($event->occurred_at->greaterThanOrEqualTo($groups[$key]['at'])) {
                $groups[$key]['summary'] = $event->summary;
                $groups[$key]['at'] = $event->occurred_at;
            }
        }

        $lines = array_map(
            fn (array $g): DigestLine => new DigestLine($g['type'], $g['subject'], $g['count'], $g['summary'], $g['at']),
            array_values($groups),
        );

        usort($lines, fn (DigestLine $a, DigestLine $b): int => $b->latestAt <=> $a->latestAt);

        return $lines;
    }
}

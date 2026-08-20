<?php

use App\Support\DigestSummary;
use Illuminate\Support\Carbon;

function evt(string $type, string $subject, string $summary, string $at): object
{
    return (object) ['type' => $type, 'subject_label' => $subject, 'summary' => $summary, 'occurred_at' => Carbon::parse($at)];
}

it('folds repeated failures of one subject into a single line', function () {
    $lines = DigestSummary::fold([
        evt('sync.failed', 'acme/demo', 'timeout', '2026-08-20 09:00:00'),
        evt('sync.failed', 'acme/demo', 'auth denied', '2026-08-20 10:00:00'),
        evt('sync.failed', 'acme/demo', 'auth denied', '2026-08-20 11:00:00'),
    ]);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->count)->toBe(3)
        ->and($lines[0]->subjectLabel)->toBe('acme/demo');
});

it('reports the most recent summary, not the first', function () {
    $lines = DigestSummary::fold([
        evt('sync.failed', 'acme/demo', 'timeout', '2026-08-20 09:00:00'),
        evt('sync.failed', 'acme/demo', 'auth denied', '2026-08-20 11:00:00'),
    ]);

    expect($lines[0]->latestSummary)->toBe('auth denied')
        ->and($lines[0]->latestAt->format('H:i'))->toBe('11:00');
});

it('does not merge across subjects', function () {
    $lines = DigestSummary::fold([
        evt('sync.failed', 'acme/demo', 'x', '2026-08-20 09:00:00'),
        evt('sync.failed', 'acme/other', 'y', '2026-08-20 09:00:00'),
    ]);

    expect($lines)->toHaveCount(2);
});

it('does not merge the same subject across different event types', function () {
    $lines = DigestSummary::fold([
        evt('sync.failed', 'acme/demo', 'x', '2026-08-20 09:00:00'),
        evt('webhook.delivery_failed', 'acme/demo', 'y', '2026-08-20 09:00:00'),
    ]);

    expect($lines)->toHaveCount(2);
});

it('orders the newest failure first', function () {
    $lines = DigestSummary::fold([
        evt('sync.failed', 'older', 'x', '2026-08-20 08:00:00'),
        evt('sync.failed', 'newer', 'y', '2026-08-20 12:00:00'),
    ]);

    expect($lines[0]->subjectLabel)->toBe('newer');
});

it('returns nothing for no events', function () {
    expect(DigestSummary::fold([]))->toBe([]);
});

it('breaks a within-group tie on identical occurred_at by letting the later-iterated event win', function () {
    $lines = DigestSummary::fold([
        evt('sync.failed', 'acme/demo', 'first seen', '2026-08-20 09:00:00'),
        evt('sync.failed', 'acme/demo', 'seen again', '2026-08-20 09:00:00'),
    ]);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->latestSummary)->toBe('seen again');
});

it('breaks a cross-group tie on identical occurred_at by first-appearance order', function () {
    $lines = DigestSummary::fold([
        evt('sync.failed', 'first-in-input', 'x', '2026-08-20 09:00:00'),
        evt('sync.failed', 'second-in-input', 'y', '2026-08-20 09:00:00'),
    ]);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->subjectLabel)->toBe('first-in-input')
        ->and($lines[1]->subjectLabel)->toBe('second-in-input');
});

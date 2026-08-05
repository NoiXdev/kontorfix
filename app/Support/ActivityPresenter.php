<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Turns activity-log rows into the flat shape the frontend renders. Shared by the
 * global activity page and the per-subject "Aktivität" tabs on the detail pages.
 */
class ActivityPresenter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function recentFor(Model $subject, int $limit = 25): array
    {
        return Activity::query()
            ->with('causer')
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Activity $a) => self::present($a))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Activity $a): array
    {
        /** @var Model|null $subject */
        $subject = $a->subject;
        /** @var Model|null $causer */
        $causer = $a->causer;

        return [
            'id' => $a->id,
            'log_name' => $a->log_name,
            'event' => $a->event,
            'description' => $a->description,
            'subject_type' => $a->subject_type ? class_basename($a->subject_type) : null,
            'subject_id' => $a->subject_id,
            'subject_label' => $subject?->getAttribute('name') ?? $subject?->getKey(),
            'causer' => $causer?->getAttribute('name'),
            'changes' => $a->attribute_changes?->toArray() ?? [],
            'created_at' => $a->created_at?->diffForHumans(),
            'created_at_exact' => $a->created_at?->toDateTimeString(),
        ];
    }
}

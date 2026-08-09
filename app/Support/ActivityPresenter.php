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
            'changes' => self::redactChanges($a->attribute_changes?->toArray() ?? []),
            'created_at' => $a->created_at?->diffForHumans(),
            'created_at_exact' => $a->created_at?->toDateTimeString(),
        ];
    }

    /**
     * Withholds any userinfo component a logged value carries.
     *
     * The activity log is a second copy of whatever a model chose to log, and it is the one
     * copy that survives rotation: a PAT written into `packages.repository_url` and logged
     * verbatim is still readable here after the operator has removed it from the live field,
     * which is precisely when it matters. `Package` redacts at the write side and a
     * migration scrubbed the rows written before it did; this is the read side, applied to
     * every subject rather than to one column, because no activity value has any business
     * rendering a credential.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private static function redactChanges(array $changes): array
    {
        foreach ($changes as $key => $value) {
            if (is_array($value)) {
                $changes[$key] = self::redactChanges($value);

                continue;
            }

            if (is_string($value) && CredentialUrl::carries($value)) {
                $changes[$key] = CredentialUrl::redact($value);
            }
        }

        return $changes;
    }
}

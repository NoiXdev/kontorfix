<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ActivityPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ActivityController extends Controller
{
    // The sort column never comes from the request — only a key that selects one. This
    // route takes untrusted query-string values and is not throttled, and this controller
    // already carries the scar from that class of input on `subject_id`/`causer` above:
    // a malformed value reached a Postgres uuid comparison, raised SQLSTATE[22P02], and
    // because nothing rendered the error every request appended a stack trace *with its
    // bound parameters* to an unrotated log. An unknown key here falls back to the
    // existing default order rather than raising. `causer` and `subject` are relations,
    // not plain columns on this table, so they are left out rather than reached for via
    // a join.
    private const SORTABLE = [
        'created_at' => 'created_at',
        'log_name' => 'log_name',
        'description' => 'description',
    ];

    /**
     * Global audit log with optional scoping to a subject (org/registry/package) or a
     * causer (user). The same endpoint backs the "view activity" links on the detail
     * pages by passing subject_type/subject_id or causer.
     */
    public function index(Request $request): Response
    {
        $log = $request->query('log');
        $subjectType = $request->query('subject_type');
        $subjectId = $request->query('subject_id');
        $causer = $request->query('causer');
        $sort = (string) $request->query('sort', '');
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $activities = Activity::query()
            ->with(['causer', 'subject'])
            ->when(is_string($log) && $log !== '', fn ($q) => $q->where('log_name', $log))
            ->when(is_string($subjectType) && $subjectType !== '', fn ($q) => $q->where('subject_type', $this->qualify($subjectType)))
            // Both ids are plain query-string values landing on a Postgres `uuid`
            // comparison, where a malformed one raised an unrendered SQLSTATE[22P02] —
            // a 500 whose stack trace, with the bound parameters, went to an unrotated
            // log. Str::isUuid decides it rather than a character class: `[0-9a-fA-F-]{36}`
            // was tried twice on this branch and 36 dashes satisfy it. An id that cannot
            // exist selects nothing, so the log comes back empty rather than unfiltered.
            ->when(is_string($subjectId) && $subjectId !== '', fn ($q) => Str::isUuid($subjectId)
                ? $q->where('subject_id', $subjectId)
                : $q->whereRaw('1 = 0'))
            ->when(is_string($causer) && $causer !== '', fn ($q) => Str::isUuid($causer)
                ? $q->where('causer_id', $causer)
                : $q->whereRaw('1 = 0'))
            ->when(
                isset(self::SORTABLE[$sort]),
                fn ($query) => $query->orderBy(self::SORTABLE[$sort], $direction),
                fn ($query) => $query->latest('id'),
            )
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Activity $a) => ActivityPresenter::present($a));

        return Inertia::render('admin/activity/Index', [
            'activities' => $activities,
            'filters' => [
                'log' => $log,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'causer' => $causer,
                'sort' => isset(self::SORTABLE[$sort]) ? $sort : null,
                'direction' => $direction,
            ],
            'logNames' => ['organization', 'registry', 'package', 'user'],
        ]);
    }

    private function qualify(string $short): string
    {
        // Accept both the short ("Package") and fully-qualified subject type.
        return str_contains($short, '\\') ? $short : 'App\\Models\\'.$short;
    }
}

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

    // The page size is a whitelist for the same reason the sort column is one: the value
    // arrives in the query string of an unthrottled route, and `->paginate($raw)` would
    // let a caller ask Postgres for 100000 rows — and, through `->through()`, ask the
    // presenter to build 100000 arrays — per request. An unlisted value falls back to the
    // default rather than raising, so a stale or hand-edited link still renders a page.
    private const PAGE_SIZES = [25, 50, 100];

    private const DEFAULT_PAGE_SIZE = 50;

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
        $sorted = isset(self::SORTABLE[$sort]);

        // `ctype_digit` before the cast, not `(int)` alone: casting turns "25abc" into a
        // valid 25 and `?per_page[]=…` (an array) into a 1, so the guard would be deciding
        // on a number the request never contained. is_string also keeps the array shape out.
        $requestedSize = $request->query('per_page');
        $perPage = is_string($requestedSize) && ctype_digit($requestedSize) && in_array((int) $requestedSize, self::PAGE_SIZES, true)
            ? (int) $requestedSize
            : self::DEFAULT_PAGE_SIZE;

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
                $sorted,
                fn ($query) => $query->orderBy(self::SORTABLE[$sort], $direction),
                fn ($query) => $query->latest('id'),
            )
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Activity $a) => ActivityPresenter::present($a));

        return Inertia::render('admin/activity/Index', [
            'activities' => $activities,
            'filters' => [
                'log' => $log,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'causer' => $causer,
                'sort' => $sorted ? $sort : null,
                // The direction that was applied, not the one that was asked for. The
                // fallback branch above orders `latest('id')` — newest first — so reporting
                // the raw `asc` default had the timeline's direction toggle offering
                // "Älteste zuerst" while showing the newest entries first.
                'direction' => $sorted ? $direction : 'desc',
                'per_page' => $perPage,
            ],
            'logNames' => ['organization', 'registry', 'package', 'user'],
            // The offered sizes come from the same constant the guard above validates
            // against, so the selector cannot offer an option the server would reject.
            'pageSizes' => self::PAGE_SIZES,
        ]);
    }

    private function qualify(string $short): string
    {
        // Accept both the short ("Package") and fully-qualified subject type.
        return str_contains($short, '\\') ? $short : 'App\\Models\\'.$short;
    }
}

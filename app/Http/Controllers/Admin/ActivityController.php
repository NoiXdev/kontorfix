<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ActivityPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ActivityController extends Controller
{
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

        $activities = Activity::query()
            ->with(['causer', 'subject'])
            ->when(is_string($log) && $log !== '', fn ($q) => $q->where('log_name', $log))
            ->when(is_string($subjectType) && $subjectType !== '', fn ($q) => $q->where('subject_type', $this->qualify($subjectType)))
            ->when(is_string($subjectId) && $subjectId !== '', fn ($q) => $q->where('subject_id', $subjectId))
            ->when(is_string($causer) && $causer !== '', fn ($q) => $q->where('causer_id', $causer))
            ->latest('id')
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

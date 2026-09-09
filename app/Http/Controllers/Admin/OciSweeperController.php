<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SweepOciStorage;
use App\Services\Oci\Sweeper\OciSweeper;
use App\Services\Registry\OciSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * The sweeper view (plate 6). Instance-wide like the sweep itself — the reachability graph
 * spans organizations, so there is no per-organization slice of this page that would mean
 * anything.
 */
class OciSweeperController extends Controller
{
    public function show(OciSweeper $sweeper, OciSettings $settings): Response
    {
        // pending() counts and removes nothing — asserted by its own test, because this
        // method runs on every GET of the page.
        $pending = $sweeper->pending();

        // The last run is READ FROM THE ACTIVITY LOG, not from a column of its own: the
        // sweep already writes this entry for the audit trail, and a second copy of the
        // same fact in system_settings would be one that can disagree with it.
        $lastRun = Activity::query()
            ->where('log_name', 'oci')
            ->where('event', 'storage_swept')
            ->latest('id')
            ->first();

        return Inertia::render('admin/oci/Sweeper', [
            'pending' => $pending->toArray(),
            'grace_hours' => $settings->blobGraceHours(),
            'blob_limit' => (int) config('kontorfix.oci_sweep_blob_limit', 1000),
            'last_run' => $lastRun === null ? null : [
                'event' => $lastRun->event,
                'created_at' => $lastRun->created_at?->diffForHumans(),
                'created_at_exact' => $lastRun->created_at?->toDateTimeString(),
                // The SweepReport the run recorded — ActivityPresenter::present() carries
                // attribute_changes, not properties, so this page reads them directly.
                'properties' => $lastRun->properties?->toArray() ?? [],
            ],
        ]);
    }

    public function run(): RedirectResponse
    {
        // Queued, not inline: a sweep moves files and can take minutes, and an HTTP request
        // must not be held open for it. ShouldBeUnique on the job is what prevents a second
        // queued sweep piling up behind this one.
        SweepOciStorage::dispatch();

        return back()->with('success', 'Bereinigung wurde eingereiht.');
    }
}

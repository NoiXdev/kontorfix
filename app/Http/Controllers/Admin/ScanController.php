<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VulnerabilitySeverity;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateScanBlockingRequest;
use App\Jobs\ScanOciArtifact;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\Package;
use App\Services\Scanner\ScanBlockGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The console's own way in: "Jetzt prüfen" for one image, and the blocking preview
 * (Task 6).
 */
class ScanController extends Controller
{
    use ScopesToAdministeredOrgs;

    /**
     * POST /admin/packages/{package}/scan — queue a scan of one manifest.
     *
     * Addressed by DIGEST in the body rather than by tag in the path: the tag grammar
     * contains dots and slashes and would need its own route constraint, while a digest is
     * a fixed shape and is what the scan is keyed on anyway.
     */
    public function store(Request $request, Package $package): RedirectResponse
    {
        $this->assertCanTouchPackage($package);

        // Same guard and the same German wording `oci:scan` already uses. Dispatching anyway
        // would flash "eingereiht" while ScanRunner::run() returns null without writing a
        // verdict — the operator would be left waiting for a result that can never arrive.
        abort_if(
            ! config('kontorfix.scanner.enabled', false),
            409,
            'Die Schwachstellenprüfung ist deaktiviert (KONTORFIX_SCANNER_ENABLED).',
        );

        $data = $request->validate(['digest' => ['required', 'string', 'regex:/^sha256:[a-f0-9]{64}$/']]);

        // Resolved WITHIN this package. A digest is only unique per repository, so a global
        // lookup would let an administrator of one organization queue work against another
        // organization's artifact by pasting its digest.
        $manifest = OciManifest::where('package_id', $package->id)->where('digest', $data['digest'])->first();

        if ($manifest === null) {
            throw ValidationException::withMessages([
                'digest' => 'Zu diesem Digest gibt es in diesem Repository kein Manifest.',
            ]);
        }

        ScanOciArtifact::dispatch($manifest->id);

        return back()->with('success', 'Die Prüfung wurde eingereiht.');
    }

    /** PUT /admin/groups/{group}/scan-blocking */
    public function update(UpdateScanBlockingRequest $request, Group $group): RedirectResponse
    {
        $this->assertAdministersGroupInScope($group);

        // forceFill: these two columns decide whether customers' pulls are refused, so this
        // write is deliberately explicit about writing exactly them rather than going through
        // a mass-assignment path another form could someday extend to reach them by accident.
        $group->forceFill([
            'scan_block_severity' => $request->validated('scan_block_severity'),
            'scan_block_grace_days' => (int) $request->validated('scan_block_grace_days'),
        ])->save();

        return back()->with('success', 'Die Blockierung wurde gespeichert.');
    }

    /**
     * POST /admin/groups/{group}/scan-preview — what the proposed setting would do.
     *
     * Read-only, and computed through ScanBlockGuard, the same class that later refuses for
     * real. A preview with its own comparison could disagree with the registry, and the
     * operator acts on the preview.
     */
    public function preview(UpdateScanBlockingRequest $request, Group $group, ScanBlockGuard $guard): JsonResponse
    {
        $this->assertAdministersGroupInScope($group);

        $severity = $request->validated('scan_block_severity');

        return response()->json($guard->preview(
            $group,
            $severity === null ? null : VulnerabilitySeverity::from((string) $severity),
            (int) $request->validated('scan_block_grace_days'),
        ));
    }
}

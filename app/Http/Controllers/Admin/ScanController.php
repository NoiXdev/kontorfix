<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Jobs\ScanOciArtifact;
use App\Models\OciManifest;
use App\Models\Package;
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
}

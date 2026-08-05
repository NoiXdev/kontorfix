<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Scope\OrgScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ScopeController extends Controller
{
    /**
     * Sets the active organization scope from the sidebar switch. An empty/absent id
     * means "all organizations". OrgScope::set() clamps the value to the organizations
     * the user actually administers, so this endpoint can never widen access — it only
     * ever narrows (or resets) what the console shows and acts on.
     */
    public function __invoke(Request $request, OrgScope $scope): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['nullable', 'uuid'],
        ]);

        $scope->set($data['organization_id'] ?? null);

        return back();
    }
}

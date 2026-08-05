<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('admin/system/Index', [
            'settings' => [
                'registration_enabled' => SystemSetting::current()->registration_enabled,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_enabled' => ['required', 'boolean'],
        ]);

        SystemSetting::current()->update($data);

        return back()->with('success', 'Systemeinstellungen gespeichert.');
    }
}

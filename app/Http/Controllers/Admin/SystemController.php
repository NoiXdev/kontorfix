<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PackageType;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\Registry\RegistryTypeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    public function show(RegistryTypeService $types): Response
    {
        return Inertia::render('admin/system/Index', [
            'settings' => [
                'registration_enabled' => SystemSetting::current()->registration_enabled,
                'enabled_registry_types' => $types->globalTypes(),
            ],
            // All selectable registry types, for rendering the toggles.
            'registryTypes' => $types->allTypes(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_enabled' => ['required', 'boolean'],
            'enabled_registry_types' => ['sometimes', 'array'],
            'enabled_registry_types.*' => [Rule::enum(PackageType::class)],
        ]);

        $update = ['registration_enabled' => $data['registration_enabled']];
        if (array_key_exists('enabled_registry_types', $data)) {
            $update['enabled_registry_types'] = array_values(array_unique($data['enabled_registry_types']));
        }

        SystemSetting::current()->update($update);

        return back()->with('success', 'Systemeinstellungen gespeichert.');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PackageType;
use App\Enums\SharedPackageRole;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\Registry\OciSettings;
use App\Services\Registry\RegistryTypeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    public function show(RegistryTypeService $types, OciSettings $oci): Response
    {
        return Inertia::render('admin/system/Index', [
            'settings' => [
                'registration_enabled' => SystemSetting::current()->registration_enabled,
                'enabled_registry_types' => $types->globalTypes(),
                // The enum's backing value, not the case: the select below binds to it.
                'shared_package_role' => SystemSetting::current()->shared_package_role->value,
                // The instance-wide ceiling for push-time repository creation; an
                // organization may narrow it on its own page, never widen it.
                'oci_auto_create_repositories' => $oci->autoCreateGloballyEnabled(),
            ],
            // All selectable registry types, for rendering the toggles.
            'registryTypes' => $types->allTypes(),
            // Both values of the sharing setting with their labels, stated by the enum. The
            // page renders what it is given rather than restating the two cases in German
            // a second time.
            'sharedPackageRoles' => SharedPackageRole::options(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_enabled' => ['required', 'boolean'],
            'enabled_registry_types' => ['sometimes', 'array'],
            'enabled_registry_types.*' => [Rule::enum(PackageType::class)],
            // `sometimes`, like `enabled_registry_types` above and deliberately not
            // `required`: three callers already PUT this endpoint with only the fields they
            // mean to change, and a required field would turn every one of them into a 422.
            // Omission cannot widen anything — it leaves the stored value untouched — and
            // the settings page always submits the whole form.
            'shared_package_role' => ['sometimes', Rule::enum(SharedPackageRole::class)],
            // `sometimes` for the same reason as the two above: the partial callers must
            // not become 422s, and an omitted field leaves the stored value alone.
            'oci_auto_create_repositories' => ['sometimes', 'boolean'],
        ]);

        $update = ['registration_enabled' => $data['registration_enabled']];
        if (array_key_exists('enabled_registry_types', $data)) {
            $update['enabled_registry_types'] = array_values(array_unique($data['enabled_registry_types']));
        }
        // Widening this is the one act that grants the `share-packages` capability to
        // somebody who does not already hold it, which is why the whole page sits behind
        // the `super` middleware: nobody below that tier can grant it to themselves.
        if (array_key_exists('shared_package_role', $data)) {
            $update['shared_package_role'] = $data['shared_package_role'];
        }
        if (array_key_exists('oci_auto_create_repositories', $data)) {
            $update['oci_auto_create_repositories'] = $data['oci_auto_create_repositories'];
        }

        SystemSetting::current()->update($update);

        return back()->with('success', 'Systemeinstellungen gespeichert.');
    }
}

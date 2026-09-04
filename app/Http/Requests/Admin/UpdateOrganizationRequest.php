<?php

namespace App\Http\Requests\Admin;

use App\Rules\UnclaimedSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An unchecked switch posts no field at all, so the absent case has to be spelled as
     * an explicit `false` before validation runs — otherwise `boolean` would never see it
     * and switching the portal off would silently save nothing.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['portal_enabled' => $this->boolean('portal_enabled')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            // Globally unique: the organization slug is the top level of the registry
            // namespace and the first segment of every registry URL it owns.
            'slug' => [
                'required', 'string', 'max:190', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('organizations', 'slug')->ignore($this->route('organization')?->id),
                // …and never equal to a registry slug: the two share one namespace in the
                // registry URL. StoreOrganizationRequest guards the create path; this is the
                // update seam App\Rules\UnclaimedSlug's docblock names — without it the
                // collision could simply be edited back in the next day. No API update route
                // exists for organizations (see routes/api.php), so unlike UpdateGroupRequest
                // this request serves only the console and `slug` can stay `required`.
                UnclaimedSlug::byRegistry(),
            ],
            'notification_cadence' => ['required', Rule::in(['hourly', 'daily', 'off'])],
            // Whether this customer has a portal at all — off makes /c/{slug} a 404. Not
            // `groups.portal_enabled`, which only hides one registry inside that portal.
            //
            // Load-bearing beyond validation: `validated()` returns only fields that carry a
            // rule, so dropping this line evicts the switch from the update entirely and the
            // column silently keeps whatever it had — switching a portal off would save
            // nothing. PortalContextTest's two console round-trip cases are what says so.
            'portal_enabled' => ['boolean'],
        ];
    }
}

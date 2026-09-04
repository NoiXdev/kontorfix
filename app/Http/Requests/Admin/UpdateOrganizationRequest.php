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
     * An unchecked switch posts no field at all, so a request that *carries* the switch has
     * its absent case spelled as an explicit `false` before validation runs — otherwise
     * `boolean` would never see it and switching the portal off would save nothing.
     *
     * Conditional on `has()`, not unconditional, because "the form sent an unchecked switch"
     * and "the request never mentioned the switch" are different intentions and only the
     * console can tell them apart by always sending every field. An unconditional merge made
     * them identical, so a partial PUT naming only `name` disabled a customer's portal as a
     * side effect. This `has()` check is the whole mechanism — `sometimes` on the rule below
     * says the same thing to a reader but changes no outcome, since `boolean` already passes
     * an absent field. StoreGroupRequest makes the mirror choice for the create path, where
     * an absent switch means `true` rather than "leave alone".
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('portal_enabled')) {
            $this->merge(['portal_enabled' => $this->boolean('portal_enabled')]);
        }
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
            //
            // `sometimes` states that an omitted switch is legal rather than defaulted, but
            // it is documentation, not mechanism: removing it reddens nothing, because
            // `boolean` already passes an absent field and `validated()` already omits one.
            // What actually makes an omitted switch mean "leave it alone" is the conditional
            // merge above — mutate that back to unconditional and the leave-alone case reddens.
            'portal_enabled' => ['sometimes', 'boolean'],
        ];
    }
}

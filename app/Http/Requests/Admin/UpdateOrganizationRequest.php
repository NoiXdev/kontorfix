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
        ];
    }
}

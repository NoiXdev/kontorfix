<?php

namespace App\Http\Requests\Admin;

use App\Models\Group;
use App\Rules\AddressableSlug;
use App\Rules\UnclaimedSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'public' => $this->boolean('public'),
            'portal_enabled' => $this->boolean('portal_enabled'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $group = $this->group();

        return [
            'name' => ['required', 'string', 'max:190'],
            // `sometimes`, not `required`: this request serves the JSON API as well as the
            // console, and a PUT that names only what it wants changed must keep leaving the
            // registry's address alone rather than being refused for omitting it. The console
            // form always sends the field, so there it behaves exactly like `required`.
            //
            // Unique within this registry's own organization, ignoring itself. The slug is
            // part of the registry URL, so a change breaks client configurations pointing
            // at the old one — the console confirms that before submitting.
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:190',
                // The registry's CURRENT slug passes through as `$unchanged`, so a registry
                // created before AddressableSlug existed can still be edited without being
                // forced to rename itself; only a NEW value has to be OCI-addressable, since
                // it becomes a path component of a repository name under path addressing.
                new AddressableSlug($group?->slug),
                Rule::unique('groups', 'slug')
                    ->where('organization_id', $group?->organization_id)
                    ->ignore($group?->id),
                // …and never equal to an organization slug: the two share one namespace in
                // the registry URL. StoreGroupRequest guards the create path; this is the
                // update seam App\Rules\UnclaimedSlug's docblock names, without which the
                // collision could simply be edited back in the next day.
                UnclaimedSlug::byOrganization(),
            ],
            'public' => ['boolean'],
            'portal_enabled' => ['boolean'],
        ];
    }

    /** The registry being updated — route-model-bound on both the console and the API route. */
    private function group(): ?Group
    {
        $group = $this->route('group');

        return $group instanceof Group ? $group : null;
    }
}

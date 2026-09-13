<?php

namespace App\Http\Requests\Admin;

use App\Models\Group;
use App\Rules\AddressableSlug;
use App\Rules\UnclaimedSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * Publishing a registry that serves the operator's shared packages is the operator's
     * call, not its customer's.
     *
     * `public` short-circuits RegistryAccessService::canAccessGroup() for anonymous
     * callers, and the read paths hand out artifact BYTES on that same unauthenticated
     * route — so this one boolean is the difference between a licensed package sitting in
     * a customer's private registry and the same package being downloadable worldwide.
     * docs/development.md already states the distribution decision belongs to the
     * operator; this is the seam where a customer admin could make it for them.
     *
     * Three deliberate narrowings:
     *
     *  - Only the false → true TRANSITION is judged. A registry the operator made public
     *    on purpose stays fully editable for everything else, instead of the customer
     *    hitting this error on every unrelated rename.
     *  - A super-admin is exempt. They own the shared packages, so publishing them is
     *    precisely the decision the rule is protecting — refusing it would remove a
     *    legitimate capability and buy nothing.
     *  - The question is asked of `assignedPackages()`, the expiry-filtered relation the
     *    read path itself uses. A lapsed assignment is not served, so it is not published
     *    either, and the guard must not be broader than the exposure it prevents.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $group = $this->group();

            if ($group === null || $group->public || ! $this->boolean('public')) {
                return;
            }

            if ($this->user()?->isSuperAdmin() === true) {
                return;
            }

            if (! $group->assignedPackages()->where('packages.shared', true)->exists()) {
                return;
            }

            $validator->errors()->add('public', 'Diese Registry führt vom Betreiber bereitgestellte Pakete. Sie öffentlich zu schalten würde diese Pakete anonym verfügbar machen — darüber entscheidet der Betreiber. Entfernen Sie die geteilten Pakete aus dieser Registry, oder wenden Sie sich an den Betreiber.');
        });
    }

    /** The registry being updated — route-model-bound on both the console and the API route. */
    private function group(): ?Group
    {
        $group = $this->route('group');

        return $group instanceof Group ? $group : null;
    }
}

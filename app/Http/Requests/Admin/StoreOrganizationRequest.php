<?php

namespace App\Http\Requests\Admin;

use App\Rules\AddressableSlug;
use App\Rules\UnclaimedSlug;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
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
            // Unique among organizations, and never equal to a registry slug: the two share
            // one namespace in the registry URL. See App\Rules\UnclaimedSlug.
            //
            // The character set lives in App\Rules\AddressableSlug rather than in a `regex:`
            // here, because it has to stay narrower than routes/registry.php's `$ociName`:
            // the slug is a path component of an OCI repository name under path addressing.
            'slug' => ['required', 'string', 'max:190', new AddressableSlug, 'unique:organizations,slug', UnclaimedSlug::byRegistry()],
        ];
    }
}

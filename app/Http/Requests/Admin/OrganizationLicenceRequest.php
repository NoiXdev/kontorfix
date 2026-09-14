<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Partial-edit semantics for an EXISTING licence, mirroring AssignmentBoundsRequest: a
 * bound the request does not name keeps whatever is stored, which is why both are
 * `sometimes` and why the controller merges with `$request->has()` rather than `??` —
 * "omitted" and "submitted null" are different instructions, and `??` cannot tell them
 * apart. The merged pair is what AssignmentWriter validates; validating the raw request in
 * isolation is what let an impossible window through before that moved into the writer.
 */
class OrganizationLicenceRequest extends FormRequest
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
            'available_until' => ['present', 'nullable', 'date_format:Y-m-d'],
            'version_min' => ['sometimes', 'nullable', 'string', 'max:190'],
            'version_max' => ['sometimes', 'nullable', 'string', 'max:190'],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Services\Package\AssignmentWriter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The SHAPE rules only for the three columns {@see AssignmentWriter} writes:
 * `available_until` a plain `Y-m-d` day, `version_min`/`version_max` plain nullable
 * strings. Both admin surfaces that create or edit an assignment share this one class —
 * `Admin\GroupController::updateAssignment()` today, `Admin\PackageAssignmentController`'s
 * "Freigaben" surface once it lands.
 *
 * `version_min`/`version_max` are `sometimes`: the console dialog behind
 * `updateAssignment()` submits only `available_until` today and omitting the bounds
 * fields entirely must not read as "clear them" — the controller preserves whatever is
 * already stored for a field this request never saw (`$request->has()`, not `filled()`
 * or `??`, is what tells "omitted" apart from "submitted null").
 *
 * TYPE-AWARE SYNTAX, ORDERING AND THE DOCKER REFUSAL ARE DELIBERATELY NOT HERE. An
 * earlier version validated `$this->input('version_min')`/`'version_max'` directly —
 * i.e. only the fields THIS request happened to submit — which is a different pair from
 * the one that ends up persisted the moment either side is omitted and the controller
 * fills it in from what is already stored. A stored `version_min=5.0.0` plus a request
 * naming only `version_max=3.0.0` passed that check (it saw one bound and skipped the
 * ordering rule), then persisted an impossible `[5.0.0, 3.0.0)` window admitting no
 * version of the package at all — reachable through this exact route, on the shipped
 * form. Any rule that can only see what one caller submitted can be fooled the same way
 * by any future caller with a different submission shape. {@see AssignmentWriter}
 * validates the EFFECTIVE, already-merged pair right before it writes it — the one
 * question that matters, asked of the one value that matters, in the one place both
 * surfaces cannot avoid going through.
 */
class AssignmentBoundsRequest extends FormRequest
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
            // A day, not an instant — see updateAssignment()'s docblock for why. `present`
            // so clearing the date stays an explicit act rather than an omitted field.
            'available_until' => ['present', 'nullable', 'date_format:Y-m-d'],
            'version_min' => ['sometimes', 'nullable', 'string', 'max:190'],
            'version_max' => ['sometimes', 'nullable', 'string', 'max:190'],
        ];
    }
}

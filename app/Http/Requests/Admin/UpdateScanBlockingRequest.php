<?php

namespace App\Http\Requests\Admin;

use App\Enums\VulnerabilitySeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The two values that decide whether a registry refuses a pull.
 *
 * Authorization is deliberately NOT here: it is `assertAdministersGroupInScope()` in the
 * controller, the same boundary every other registry mutation goes through, so there is one
 * place to read rather than two that have to agree.
 */
class UpdateScanBlockingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Null is a real, meaningful value here — "this registry does not block" — and
            // it is the default, so `nullable` rather than `sometimes`.
            'scan_block_severity' => ['nullable', Rule::enum(VulnerabilitySeverity::class)],
            // 0 is legal and means "block as soon as a finding is recorded". An operator
            // who wants that should be able to say it; what is refused is a negative value,
            // which would mean a grace that expired before the finding existed.
            'scan_block_grace_days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scan_block_severity.enum' => 'Wählen Sie einen der fünf Schweregrade oder „keine Blockierung".',
            'scan_block_grace_days.min' => 'Die Schonfrist kann nicht negativ sein.',
            'scan_block_grace_days.max' => 'Die Schonfrist ist auf 365 Tage begrenzt.',
        ];
    }
}

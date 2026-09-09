<?php

namespace App\Http\Requests\Admin;

use App\Support\Retention\RetentionRuleSetValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Store and update share one request: the fields, the rule grammar and the set-level
 * invariants are identical for both, and the unique-name rule ignores the routed policy on
 * update by reading the route parameter. The rule-set grammar itself lives in
 * RetentionRuleSetValidator — the policy form, the preview endpoint and the package's
 * inline rules all submit the same shape, and three drifting copies of one grammar is how
 * a rule gets stored that another writer refuses.
 */
class RetentionPolicyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('retention_policies', 'name')->ignore($this->route('retention_policy')),
            ],
            'rules' => ['required', 'array', 'min:1', RetentionRuleSetValidator::rule()],
            // Publication to every organization, read-only there. Operator-only by way of
            // the route group; `sometimes` so callers that do not send it change nothing.
            'is_global' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The validated payload with every rule normalised through the value object.
     *
     * @return array{name: string, rules: list<array<string, mixed>>, is_global?: bool}
     */
    public function policyData(): array
    {
        /** @var array{name: string, rules: list<array<string, mixed>>, is_global?: bool} $validated */
        $validated = $this->validated();

        $data = [
            'name' => $validated['name'],
            'rules' => RetentionRuleSetValidator::normalise($validated['rules']),
        ];

        if (array_key_exists('is_global', $validated)) {
            $data['is_global'] = $validated['is_global'];
        }

        return $data;
    }
}

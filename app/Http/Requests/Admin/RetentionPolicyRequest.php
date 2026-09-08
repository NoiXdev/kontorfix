<?php

namespace App\Http\Requests\Admin;

use App\Support\Retention\RetentionRule;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use ValueError;

/**
 * Store and update share one request: the fields, the rule grammar and the one policy-level
 * invariant (at least one keep-rule) are identical for both, and the unique-name rule
 * ignores the routed policy on update by reading the route parameter.
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
            'rules' => ['required', 'array', 'min:1', $this->validRuleSet(...)],
        ];
    }

    /**
     * Each element through RetentionRule::fromArray() — the same constructor the evaluator
     * uses, so the form cannot store a rule the evaluator would refuse to build. The
     * policy-level invariant on top: at least one keep-rule. A policy of shields alone
     * expresses no deletion at all (the evaluator treats it as inert), so accepting one
     * would store a policy the operator believes is protecting something while it does
     * nothing. Refused here for the form's writes; the evaluator's own guard covers rows a
     * seeder, a migration or a hand-edited jsonb produces.
     */
    private function validRuleSet(string $attribute, mixed $value, Closure $fail): void
    {
        $hasKeep = false;

        foreach (is_array($value) ? $value : [] as $raw) {
            try {
                $rule = RetentionRule::fromArray(is_array($raw) ? $raw : []);
            } catch (ValueError|InvalidArgumentException) {
                $fail('Eine Regel ist unvollständig oder unbekannt.');

                return;
            }

            $hasKeep = $hasKeep || ! $rule->type->isShield();
        }

        if (! $hasKeep) {
            $fail('Mindestens eine Behalte-Regel ist nötig — „Nie löschen“ allein entfernt nichts.');
        }
    }

    /**
     * The validated payload with every rule normalised through the value object, so what is
     * stored is exactly what fromArray() accepted — no stray keys a client sent along.
     *
     * @return array{name: string, rules: list<array<string, mixed>>}
     */
    public function policyData(): array
    {
        /** @var array{name: string, rules: list<array<string, mixed>>} $validated */
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'rules' => array_map(
                fn (array $raw): array => RetentionRule::fromArray($raw)->toArray(),
                $validated['rules'],
            ),
        ];
    }
}

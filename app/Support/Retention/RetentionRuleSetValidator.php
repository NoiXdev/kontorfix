<?php

namespace App\Support\Retention;

use Closure;
use InvalidArgumentException;
use ValueError;

/**
 * The one validator for a submitted rule set, shared by the policy form, the preview
 * endpoint and the package's inline rules — three writers of the same grammar, and the
 * two set-level invariants (at least one effective rule; at most one keep_untagged) must
 * not exist as three drifting copies.
 */
final class RetentionRuleSetValidator
{
    /**
     * A Laravel custom-rule callable: `['array', RetentionRuleSetValidator::rule()]`.
     */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $hasEffect = false;
            $untaggedRules = 0;

            foreach (is_array($value) ? $value : [] as $raw) {
                try {
                    $rule = RetentionRule::fromArray(is_array($raw) ? $raw : []);
                } catch (ValueError|InvalidArgumentException) {
                    $fail('Eine Regel ist unvollständig oder unbekannt.');

                    return;
                }

                // A keep-rule or an untagged rule both DO something; a shield alone does
                // not. The refusal is about inert-but-protective-looking rule sets, so
                // keep_untagged counts as effect even though it never keeps a tag.
                $hasEffect = $hasEffect || ! $rule->type->isShield();

                if (! $rule->type->affectsTags()) {
                    $untaggedRules++;
                }
            }

            if (! $hasEffect) {
                $fail('Mindestens eine Behalte-Regel ist nötig — „Nie löschen“ allein entfernt nichts.');
            }

            // Two windows would mean a silent max() (or min(), depending on the reader) —
            // refused instead of decided quietly.
            if ($untaggedRules > 1) {
                $fail('Höchstens eine Regel „Ungetaggte behalten“ pro Regelsatz.');
            }
        };
    }

    /**
     * Every rule normalised through the value object, so what is stored is exactly what
     * fromArray() accepted — no stray keys a client sent along.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    public static function normalise(array $rules): array
    {
        return array_map(fn (array $raw): array => RetentionRule::fromArray($raw)->toArray(), $rules);
    }
}

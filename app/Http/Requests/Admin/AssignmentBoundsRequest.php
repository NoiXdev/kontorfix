<?php

namespace App\Http\Requests\Admin;

use App\Enums\PackageType;
use App\Models\Package;
use App\Services\Package\AssignmentWriter;
use App\Support\Licence\Pep440Version;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use UnexpectedValueException;

/**
 * Validates the three columns {@see AssignmentWriter} writes:
 * `available_until`'s syntax, unchanged from before extraction, plus the type-aware
 * syntax and ordering of `version_min`/`version_max`. Both admin surfaces that create or
 * edit an assignment share this one class — `Admin\GroupController::updateAssignment()`
 * today, `Admin\PackageAssignmentController`'s "Freigaben" surface once it lands — so the
 * two can never come to validate bounds differently.
 *
 * `version_min`/`version_max` are `sometimes`: the console dialog behind
 * `updateAssignment()` submits only `available_until` today and omitting the bounds
 * fields entirely must not read as "clear them" — the controller preserves whatever is
 * already stored for a field this request never saw. A field submitted as an explicit
 * `null`, by contrast, IS a request to clear that side, and `has()` (key presence) rather
 * than `filled()` is what tells the two apart.
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

    /**
     * The cross-field and type-aware rules a plain `rules()` array cannot express: syntax
     * depends on the route-bound package's ecosystem, and ordering depends on both fields
     * at once. Run in `after()` rather than as a `Rule` object on either field alone, so a
     * single pass has both values and the package in hand.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $package = $this->route('package');
            if (! $package instanceof Package) {
                return;
            }

            // Absence and an explicit null are the same "no bound here" for THIS check —
            // whether a bound is being narrowed or left alone is the controller's
            // question, not a syntax one.
            $min = $this->input('version_min');
            $max = $this->input('version_max');

            if ($min === null && $max === null) {
                return;
            }

            if ($package->type === PackageType::Docker) {
                $validator->errors()->add('version_min', 'Für Docker-Pakete gibt es keine Versionsgrenzen.');

                return;
            }

            $minValid = $min === null || $this->isValidBound($package->type, $min);
            $maxValid = $max === null || $this->isValidBound($package->type, $max);

            if (! $minValid) {
                $validator->errors()->add('version_min', 'Diese Version ist syntaktisch ungültig.');
            }

            if (! $maxValid) {
                $validator->errors()->add('version_max', 'Diese Version ist syntaktisch ungültig.');
            }

            if ($minValid && $maxValid && $min !== null && $max !== null
                && ! $this->isLessThan($package->type, $min, $max)) {
                $validator->errors()->add('version_min', 'Die Untergrenze muss kleiner als die Obergrenze sein.');
            }
        });
    }

    /**
     * Composer and npm share `composer/semver`'s parser; PyPI cannot reuse it — see
     * {@see Pep440Version}'s docblock for why — and goes through that instead.
     * `VersionParser::normalize()` throws on anything it cannot parse rather than
     * returning a sentinel, so the syntax check is the catch.
     */
    private function isValidBound(PackageType $type, string $version): bool
    {
        if ($type === PackageType::Python) {
            return Pep440Version::parse($version) !== null;
        }

        try {
            (new VersionParser)->normalize($version);

            return true;
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    /**
     * Both bounds already passed {@see isValidBound()} by the time this runs, so PyPI's
     * `parse()` cannot return null here — a bound that failed to parse already produced
     * its own syntax error above and this comparison is skipped for it (see the caller).
     */
    private function isLessThan(PackageType $type, string $min, string $max): bool
    {
        if ($type === PackageType::Python) {
            /** @var Pep440Version $parsedMin */
            $parsedMin = Pep440Version::parse($min);
            /** @var Pep440Version $parsedMax */
            $parsedMax = Pep440Version::parse($max);

            return $parsedMin->compareTo($parsedMax) < 0;
        }

        return Comparator::lessThan($min, $max);
    }
}

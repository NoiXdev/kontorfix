<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PackageType;
use App\Enums\RetentionRuleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RetentionPolicyRequest;
use App\Models\Package;
use App\Models\RetentionPolicy;
use App\Models\SystemSetting;
use App\Services\Oci\Retention\RetentionRunner;
use App\Support\Retention\RetentionDecision;
use App\Support\Retention\RetentionRule;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use ValueError;

/**
 * Instance-wide administration (the `super` route group): policies are operator-defined and
 * have no per-organization dimension — and assigning or editing one must not be a lever a
 * customer-organization admin can pull to opt their packages out of the instance default.
 */
class RetentionPolicyController extends Controller
{
    /**
     * The dry-run page evaluates at most this many packages, ordered by name, and SAYS so
     * (`packages_truncated`) — a bounded page that states its bound, never a silent
     * truncation that reads as full coverage. The bound exists because the instance
     * default's governed set is "every Docker package without a policy of its own", which
     * on a large registry is not a page.
     */
    private const DRY_RUN_PACKAGE_LIMIT = 200;

    public function __construct(private RetentionRunner $runner) {}

    public function index(): Response
    {
        $defaultId = SystemSetting::current()->retention_policy_id;

        return Inertia::render('admin/retention/Index', [
            'policies' => RetentionPolicy::query()->orderBy('name')->get()->map(fn (RetentionPolicy $policy): array => [
                'id' => $policy->id,
                'name' => $policy->name,
                'rules' => array_map(
                    fn (RetentionRule $rule): string => $rule->describe(),
                    $this->runner->rulesOf($policy),
                ),
                'package_count' => $this->runner->packagesFor($policy)->count(),
                'is_instance_default' => $policy->id === $defaultId,
            ])->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/retention/Form', [
            'policy' => null,
            'ruleTypes' => RetentionRuleType::options(),
            'dockerPackages' => $this->dockerPackages(),
        ]);
    }

    public function store(RetentionPolicyRequest $request): RedirectResponse
    {
        RetentionPolicy::create($request->policyData());

        return redirect()->route('admin.retention-policies.index')->with('success', 'Richtlinie angelegt.');
    }

    public function edit(RetentionPolicy $retentionPolicy): Response
    {
        return Inertia::render('admin/retention/Form', [
            'policy' => [
                'id' => $retentionPolicy->id,
                'name' => $retentionPolicy->name,
                'rules' => $retentionPolicy->rules,
            ],
            'ruleTypes' => RetentionRuleType::options(),
            'dockerPackages' => $this->dockerPackages(),
        ]);
    }

    public function update(RetentionPolicyRequest $request, RetentionPolicy $retentionPolicy): RedirectResponse
    {
        $retentionPolicy->update($request->policyData());

        return redirect()->route('admin.retention-policies.index')->with('success', 'Richtlinie gespeichert.');
    }

    public function destroy(RetentionPolicy $retentionPolicy): RedirectResponse
    {
        // Deleting the instance default would nullOnDelete the settings column and switch
        // retention off instance-wide, silently. Refused with the remedy named: unset the
        // default first. The schema survives the delete either way (nullOnDelete); this
        // guard is about it not happening as a side effect.
        abort_if(
            SystemSetting::current()->retention_policy_id === $retentionPolicy->id,
            409,
            'Diese Richtlinie ist die Instanz-Vorgabe. Bitte zuerst die Vorgabe in den Systemeinstellungen entfernen.',
        );

        $retentionPolicy->delete();

        return redirect()->route('admin.retention-policies.index')->with('success', 'Richtlinie gelöscht.');
    }

    /** The report over every package the SAVED policy governs — plate 5. */
    public function dryRun(RetentionPolicy $retentionPolicy): Response
    {
        $governed = $this->runner->packagesFor($retentionPolicy)->orderBy('name');

        $total = (clone $governed)->count();
        $evaluated = $governed->limit(self::DRY_RUN_PACKAGE_LIMIT)->get();

        $reports = [];
        $removed = 0;
        $kept = 0;

        foreach ($evaluated as $package) {
            $report = $this->runner->dryRun($package);

            if ($report === null) {
                continue;
            }

            $reports[] = $report->toArray();
            $removed += count($report->removed());
            $kept += count($report->kept());
        }

        return Inertia::render('admin/retention/DryRun', [
            'policy' => ['id' => $retentionPolicy->id, 'name' => $retentionPolicy->name],
            'reports' => $reports,
            'totals' => ['removed' => $removed, 'kept' => $kept],
            'packages_evaluated' => $evaluated->count(),
            'packages_truncated' => max(0, $total - $evaluated->count()),
        ]);
    }

    /**
     * The editor panel's evaluation of the UNSAVED rules currently in the form, against one
     * picked package. JSON rather than an Inertia page: the panel updates in place while
     * the operator types, and a page visit would discard exactly the unsaved state it is
     * there to preview.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_id' => ['required', 'uuid', 'exists:packages,id'],
            'rules' => ['required', 'array', 'min:1', function (string $attribute, mixed $value, Closure $fail): void {
                $untaggedRules = 0;

                foreach (is_array($value) ? $value : [] as $raw) {
                    try {
                        $rule = RetentionRule::fromArray(is_array($raw) ? $raw : []);
                    } catch (ValueError|InvalidArgumentException) {
                        $fail('Eine Regel ist unvollständig oder unbekannt.');

                        return;
                    }

                    if (! $rule->type->affectsTags()) {
                        $untaggedRules++;
                    }
                }

                if ($untaggedRules > 1) {
                    $fail('Höchstens eine Regel „Ungetaggte behalten“ pro Richtlinie.');
                }
            }],
        ]);

        /** @var Package $package */
        $package = Package::query()->where('type', PackageType::Docker)->findOrFail($data['package_id']);

        $decisions = $this->runner->previewWithRules($package, $data['rules']);

        return response()->json([
            'tags' => array_map(fn (RetentionDecision $decision): array => [
                'name' => $decision->tag->name,
                'pushed_at' => $decision->tag->pushed_at?->toDateTimeString(),
                'keep' => $decision->keep,
                'reason' => $decision->reasons === [] ? null : implode(', ', $decision->reasons),
            ], $decisions),
        ]);
    }

    /**
     * The manual run. Re-resolved server-side rather than applying a list the client sent:
     * the confirmation dialog's numbers are advisory, the evaluation at apply time is what
     * counts.
     */
    public function apply(Request $request, RetentionPolicy $retentionPolicy): RedirectResponse
    {
        $removed = 0;
        $packages = 0;

        foreach ($this->runner->packagesFor($retentionPolicy)->lazyById() as $package) {
            $report = $this->runner->apply($package, $request->user());

            if ($report === null || $report->removed() === []) {
                continue;
            }

            $packages++;
            $removed += count($report->removed());
        }

        return back()->with('success', sprintf(
            '%d Tag(s) in %d Repository/Repositories entfernt. Speicherplatz wird erst von der Speicherbereinigung freigegeben, nach Ablauf der Schonfrist.',
            $removed,
            $packages,
        ));
    }

    /**
     * The preview panel's package picker: every Docker repository, by name. Bounded the
     * same way the dry run is, for the same reason.
     *
     * @return list<array{id: string, name: string}>
     */
    private function dockerPackages(): array
    {
        return Package::query()
            ->where('type', PackageType::Docker)
            ->orderBy('name')
            ->limit(self::DRY_RUN_PACKAGE_LIMIT)
            ->get(['id', 'name'])
            ->map(fn (Package $package): array => ['id' => (string) $package->id, 'name' => $package->name])
            ->all();
    }
}

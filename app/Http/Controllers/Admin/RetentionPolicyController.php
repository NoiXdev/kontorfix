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
use App\Support\Retention\RetentionRuleSetValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Policy CRUD, the preview/dry-run/apply tooling, and `index()` (the only action here reached
 * from the `operator` route group too — see routes/web.php). Every other action lives in the
 * `super` route group: creating, editing, publishing (`is_global`) and deleting a rule set are
 * operator-only, since a policy has no per-organization dimension by itself.
 *
 * Assigning a PUBLISHED policy to one's own package, or writing that package's own inline
 * rules, is a separate authorization question answered in `PackageController::updateRetention()`
 * — not here, and not a lever this controller's own gate covers.
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

    public function index(Request $request): Response
    {
        $defaultId = SystemSetting::current()->retention_policy_id;
        $canManage = (bool) $request->user()?->isSuperAdmin();

        // An org admin sees only what has been PUBLISHED to them: the global policies,
        // read-only. Which other rule sets exist on the instance is operator configuration,
        // and this list must not double as a catalogue of it. Everything mutating stays in
        // the super route group regardless of what this page shows.
        $policies = RetentionPolicy::query()
            ->when(! $canManage, fn ($query) => $query->where('is_global', true))
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/retention/Index', [
            'policies' => $policies->map(fn (RetentionPolicy $policy): array => [
                'id' => $policy->id,
                'name' => $policy->name,
                'rules' => array_map(
                    fn (RetentionRule $rule): string => $rule->describe(),
                    $this->runner->rulesOf($policy),
                ),
                'is_global' => $policy->is_global,
                'package_count' => $canManage ? $this->runner->packagesFor($policy)->count() : null,
                'is_instance_default' => $policy->id === $defaultId,
            ])->all(),
            'can_manage' => $canManage,
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
                'is_global' => $retentionPolicy->is_global,
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
     * The editor panel's evaluation of the UNSAVED rules currently in the form — the rule
     * summary always, and the tag-by-tag verdict once a package has been picked to try them
     * against. JSON rather than an Inertia page: the panel updates in place while the
     * operator types, and a page visit would discard exactly the unsaved state it is there
     * to preview.
     *
     * `package_id` is optional: the summary line is worth showing the instant the rules
     * validate, before the operator has picked a repository to test them against — and the
     * live editor re-runs this on every keystroke, not only once a package is chosen.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_id' => ['sometimes', 'nullable', 'uuid', 'exists:packages,id'],
            'rules' => ['required', 'array', 'min:1', RetentionRuleSetValidator::rule()],
        ]);

        $tags = null;
        if (! empty($data['package_id'])) {
            /** @var Package $package */
            $package = Package::query()->where('type', PackageType::Docker)->findOrFail($data['package_id']);

            $tags = array_map(
                fn (RetentionDecision $decision): array => $decision->toArray(),
                $this->runner->previewWithRules($package, $data['rules']),
            );
        }

        return response()->json([
            'summary' => RetentionRule::describeAll($data['rules']),
            'tags' => $tags,
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

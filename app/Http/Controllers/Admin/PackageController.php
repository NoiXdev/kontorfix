<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Http\Requests\Admin\UpdatePackageAbandonmentRequest;
use App\Jobs\SyncPackage;
use App\Models\GitCredential;
use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PythonDist;
use App\Rules\NotRedactedCredentialUrl;
use App\Services\Package\PackageDependencies;
use App\Services\Package\SharedAssignment;
use App\Services\Registry\RegistryTypeService;
use App\Services\Registry\RegistryUrl;
use App\Services\Scope\OrgScope;
use App\Services\Vcs\RepositoryProbe;
use App\Support\ActivityPresenter;
use App\Support\CredentialUrl;
use App\Support\RepositoryAuthority;
use App\Support\RepositoryUrlRules;
use App\Support\VersionOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    use ScopesToAdministeredOrgs;

    // The sort column never comes from the request — only a key that selects one. This
    // route takes untrusted query-string values and is not throttled, and this controller
    // already carries the scar from that: a malformed `group` value reached a Postgres
    // uuid comparison, raised SQLSTATE[22P02], and because nothing rendered the error
    // every request appended a stack trace *with its bound parameters* to an unrotated
    // log. An unknown key here falls back to the existing default order rather than
    // raising. `groups_count` is sortable because `withCount('groups')` below puts a real
    // SQL alias on the query.
    private const SORTABLE = [
        'name' => 'name',
        'type' => 'type',
        'sync_status' => 'sync_status',
        'groups_count' => 'groups_count',
        'synced_at' => 'synced_at',
    ];

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $type = $request->query('type');
        $status = $request->query('status');
        $group = $request->query('group');
        $sort = (string) $request->query('sort', '');
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $packages = $this->scopePackageQuery(Package::query())
            ->withCount('groups')
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->when(in_array($type, ['composer', 'npm', 'python'], true), fn ($query) => $query->where('type', $type))
            ->when(in_array($status, ['pending', 'syncing', 'synced', 'failed'], true), fn ($query) => $query->where('sync_status', $status))
            // `group` is a plain query-string value on a route with no throttle, and it
            // lands on a Postgres `uuid` comparison: a malformed one raised
            // SQLSTATE[22P02], which nothing renders, so every request appended a stack
            // trace with its bound parameters to an unrotated log. Str::isUuid decides
            // this — not a character-class pattern; the two attempts at one on this branch
            // were both satisfied by 36 dashes. A value that cannot be a group id matches
            // no group, so the answer is an empty list, not an unfiltered one.
            ->when(is_string($group) && $group !== '', fn ($query) => Str::isUuid($group)
                ? $query->whereHas('groups', fn ($g) => $g->whereKey($group))
                : $query->whereRaw('1 = 0'))
            ->when(
                isset(self::SORTABLE[$sort]),
                fn ($query) => $query->orderBy(self::SORTABLE[$sort], $direction),
                fn ($query) => $query->latest(),
            )
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Package $p) => [
                'id' => $p->id,
                'type' => $p->type,
                'source_mode' => $p->source_mode->value,
                'name' => $p->name,
                'sync_status' => $p->sync_status,
                'sync_error' => $p->sync_error,
                'groups_count' => $p->groups_count,
                'synced_at' => $p->synced_at?->diffForHumans(),
                'is_abandoned' => $p->isAbandoned(),
                'shared' => $p->shared,
            ]);

        return Inertia::render('admin/packages/Index', [
            'packages' => $packages,
            'groups' => $this->scopeGroupQuery(Group::query())->orderBy('name')->get(['id', 'name', 'slug']),
            'filters' => [
                'q' => $q,
                'type' => $type,
                'status' => $status,
                'group' => $group,
                'sort' => isset(self::SORTABLE[$sort]) ? $sort : null,
                'direction' => $direction,
            ],
            // Only instance-enabled registry types are offered in the filter.
            'registryTypes' => app(RegistryTypeService::class)->globalTypes(),
            // Source-mode options per package type. Kept here too (not just on create()):
            // NpmSourceModeTest asserts on this prop directly against `GET /admin/packages`,
            // independent of whether the current Index.vue happens to render it — removing it
            // would break that pre-existing, unrelated-to-this-task coverage.
            'sourceModes' => $this->sourceModesPayload(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/packages/Create', [
            'groups' => $this->scopeGroupQuery(Group::query())->orderBy('name')->get(['id', 'name', 'slug']),
            // Only instance-enabled registry types are offered when creating a package.
            'registryTypes' => app(RegistryTypeService::class)->globalTypes(),
            // Source-mode options per package type. npm has exactly one, so the create page
            // hides the selector for it rather than offering a rejected choice.
            'sourceModes' => $this->sourceModesPayload(),
            // Managed git credentials the user may assign (never exposes the token).
            'gitCredentials' => GitCredential::whereIn('organization_id', $this->scopedOrgIds())
                ->orderBy('name')->get(['id', 'name', 'provider'])
                ->map(fn (GitCredential $c) => ['id' => $c->id, 'name' => $c->name, 'provider' => $c->provider->value]),
        ]);
    }

    /**
     * Source-mode options per package type, keyed by type value. Shared by index() (kept for
     * NpmSourceModeTest, see above) and create() (which actually renders it).
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    private function sourceModesPayload(): array
    {
        return collect(PackageType::cases())
            ->mapWithKeys(fn (PackageType $t): array => [$t->value => array_map(
                fn (PackageSourceMode $m): array => ['value' => $m->value, 'label' => $m->label()],
                PackageSourceMode::allowedFor($t)
            )])
            ->all();
    }

    public function show(Request $request, Package $package, PackageDependencies $deps, RegistryUrl $registryUrl): Response
    {
        $this->assertCanTouchPackage($package);

        // `groups.organization:id,slug` on top of the group's own columns: the registry list
        // below prints each registry's URL, and RegistryUrl reads the organization's slug for
        // the first segment. Without the relation this would be one lazy load per row; without
        // `slug` in it, a silent null and a /r//{groupSlug} on screen.
        $package->load(['versions', 'groups:id,name,slug,organization_id', 'groups.organization:id,slug']);
        $package->setRelation('versions', VersionOrder::sort($package->versions));

        // `assertCanTouchPackage()` asserts that the package's OWNER is in the active scope
        // (it compares `organization_id` directly since Task 7); it says nothing about the
        // registries the package is attached to. Serialising the whole relation therefore
        // handed a customer-org admin the id, name and slug of every *other* organization's
        // registry the package is shared into. Such a row can no longer be created — the
        // attach guard and the enforcement migration both refuse it — but this filter stays:
        // it is defensive against pre-invariant data, and the caller has no business reading
        // those rows either way, so they get a count instead.
        $scope = app(OrgScope::class);
        $visibleGroups = $scope->spansAllOrganizations()
            ? $package->groups
            : $package->groups->whereIn('organization_id', $this->scopedOrgIds());

        // Python is file-centric (multiple dists per version), so its "versions" and stats
        // come from the python_dists table rather than package_versions.
        $isPython = $package->type === PackageType::Python;
        $dists = $isPython ? $package->pythonDists()->orderByDesc('uploaded_at')->get() : collect();

        return Inertia::render('admin/packages/Show', [
            'package' => [
                'id' => $package->id,
                'type' => $package->type->value,
                'source_mode' => $package->source_mode->value,
                'is_git_sourced' => $package->isGitSourced(),
                'name' => $package->name,
                'description' => $package->description,
                'readme_html' => $package->readme_html,
                // The one read path of this column that did not redact. An inline
                // `https://x-access-token:<PAT>@…` is a supported shape here, and the
                // reader may be an admin of a *different* tenant sharing the registry —
                // the prior-#10 shared-pool state. Aligned with the five siblings that
                // already redact; NotRedactedCredentialUrl on the update route refuses the
                // marker on its way back in, so a withheld value cannot silently destroy
                // the credential it was withheld from.
                'repository_url' => CredentialUrl::redact($package->repository_url),
                'git_credential_id' => $package->git_credential_id,
                'has_repository_token' => $package->repository_token !== null,
                'sync_status' => $package->sync_status->value,
                'sync_error' => $package->sync_error,
                'synced_at' => $package->synced_at?->diffForHumans(),
                'abandoned_at' => $package->abandoned_at?->toDateString(),
                'replacement_package' => $package->replacement_package,
                'abandonment_reason' => $package->abandonment_reason,
                'shared' => $package->shared,
            ],
            // Whether the viewer holds the share-packages ability at all — passed rather
            // than re-derived in Vue so the front end never restates the gate's rule (the
            // instance setting it reads is not itself exposed to the client).
            'canSharePackages' => (bool) $request->user()?->can('share-packages'),
            // Managed credentials assignable to this package (never exposes the token).
            'gitCredentials' => GitCredential::whereIn('organization_id', $this->scopedOrgIds())
                ->orderBy('name')->get(['id', 'name', 'provider'])
                ->map(fn (GitCredential $c) => ['id' => $c->id, 'name' => $c->name, 'provider' => $c->provider->value]),
            'versions' => $package->versions->map(fn (PackageVersion $v) => [
                'version' => $v->version_pretty ?? $v->version,
                'released_at' => $v->released_at?->toDateString(),
                'reference' => $v->source_reference,
                'dependencies' => $deps->for($package->type, $v->metadata ?? []),
                'download_count' => $v->download_count,
                'dist_size' => $v->dist_size,
            ]),
            // Python distribution files (empty for other types).
            'pythonDists' => $dists->map(fn (PythonDist $d) => [
                'filename' => $d->filename,
                'version' => $d->version,
                'filetype' => $d->filetype,
                'size' => $d->size,
                'download_count' => $d->download_count,
                'uploaded_at' => $d->uploaded_at?->toDateString(),
            ]),
            'groups' => $visibleGroups->map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name, 'slug' => $g->slug, 'url_path' => $registryUrl->path($g)])->values(),
            'sharedElsewhere' => $package->groups->count() - $visibleGroups->count(),
            'stats' => $isPython ? [
                'downloads' => (int) $dists->sum('download_count'),
                'storage_bytes' => (int) $dists->sum('size'),
                'versions' => $dists->pluck('version')->unique()->count(),
            ] : [
                'downloads' => (int) $package->versions->sum('download_count'),
                'storage_bytes' => (int) $package->versions->sum('dist_size'),
                'versions' => $package->versions->count(),
            ],
            'activities' => ActivityPresenter::recentFor($package),
        ]);
    }

    /**
     * The package's sync status alone, for the detail page to reconcile itself against.
     *
     * `PackageSynced` is broadcast on the `operator` channel the moment the job finishes.
     * Creating a package dispatches `SyncPackage` and redirects straight to this package's
     * detail page, so the browser still has to load the page, open the WebSocket,
     * authenticate at `/broadcasting/auth` and subscribe — and for a small repository the
     * job routinely wins that race. Reverb does not replay to a channel nobody had joined,
     * so the badge stayed on "Wartet" until somebody reloaded by hand. Realtime is also
     * optional here: `resources/js/echo.ts` only builds `window.Echo` when a Reverb key was
     * present at asset-build time, and with none the page would never have been corrected
     * at all.
     *
     * Deliberately not the `show` action with a partial reload: that one loads versions,
     * registries with their organizations, Python distributions and the activity log to
     * answer a question about two columns. Same guard as every other read of this package.
     */
    public function syncStatus(Package $package): JsonResponse
    {
        $this->assertCanTouchPackage($package);

        return response()->json([
            'status' => $package->sync_status->value,
            // Same exposure as `show` above, behind the same guard — the detail page
            // prints this text next to the failed badge.
            'error' => $package->sync_error,
        ]);
    }

    public function probe(Request $request, RepositoryProbe $probe): JsonResponse
    {
        // Same URL shape — and the same German messages — as StorePackageRequest, from the
        // one shared definition. The create mask makes a successful probe a precondition
        // for saving a git-sourced package, so a URL this endpoint rejects for a reason the
        // operator cannot read is a package that can never be created.
        $data = $request->validate([
            'type' => ['required', Rule::enum(PackageType::class)],
            'repository_url' => array_merge(['required'], RepositoryUrlRules::shape()),
            'repository_token' => ['nullable', 'string', 'max:500'],
            'git_credential_id' => ['nullable', 'uuid', 'exists:git_credentials,id'],
        ], RepositoryUrlRules::messages());

        // A managed credential (if referenced and administered) takes precedence over an
        // inline token; otherwise the inline token is treated as a GitHub token.
        $token = $data['repository_token'] ?? null;
        $provider = null;
        $username = null;
        if (! empty($data['git_credential_id'])) {
            $credential = GitCredential::findOrFail($data['git_credential_id']);
            // Refuse loudly rather than silently probing without the credential: a
            // reference to a foreign organization's secret is never a legitimate request.
            $this->assertAdministersOrg($credential->organization_id);
            // A stored token is bound to one host and may not be probed against another.
            $this->assertCredentialPermits($credential, $data['repository_url']);

            $token = $credential->token;
            $provider = $credential->provider;
            $username = $credential->username;
        }

        $result = $probe->probe(PackageType::from($data['type']), $data['repository_url'], $token, $provider, $username);

        // This endpoint makes the instance dial an address the caller names, and it left no
        // trace at all — so an org Maintainer sweeping hosts behind the address policy was
        // indistinguishable from nobody having used the console. The URL is redacted because
        // an inline `https://x-access-token:<PAT>@…` is a supported shape here, and a token
        // in the log survives the rotation that is the response to leaking it.
        Log::info('Repository probe.', [
            'user_id' => $request->user()?->id,
            'repository_url' => CredentialUrl::redact($data['repository_url']),
            'git_credential_id' => $data['git_credential_id'] ?? null,
            'ok' => $result['ok'],
        ]);

        return response()->json($result);
    }

    public function store(StorePackageRequest $request, SharedAssignment $sharedAssignment): RedirectResponse|JsonResponse
    {
        // A package may only be attached to registries the user administers, so it can
        // never be slipped into another organization's registry.
        $groupIds = $request->validated('group_ids', []);
        foreach ($groupIds as $groupId) {
            $this->assertAdministersGroup(Group::findOrFail($groupId));
        }

        // A package must not claim a name one of these registries already serves through a
        // shared package: the end state would be one registry serving a shared and an own
        // package under one name, which SharedAssignment refuses from the assignment side.
        // `shared` is fillable but StorePackageRequest has no rule for it, so it never
        // reaches $request->safe() and the row below is always created non-shared — if that
        // ever changes, this call has to grow the assignment-side check too.
        $sharedAssignment->assertNameUnclaimedIn(
            $groupIds,
            PackageType::from($request->validated('type')),
            (string) $request->validated('name'),
        );

        // A referenced credential must belong to an organization the user administers,
        // and may only be paired with a repository on the host it is bound to.
        if ($request->filled('git_credential_id')) {
            $credential = GitCredential::findOrFail($request->validated('git_credential_id'));
            $this->assertAdministersOrg($credential->organization_id);
            $this->assertCredentialPermits($credential, $request->validated('repository_url'));
        }

        // The source mode is authoritative (Composer is always git; npm/Python honour the
        // submitted mode) so the stored column is truthful regardless of what was sent.
        $attributes = $request->safe()->except('group_ids');
        $attributes['source_mode'] = $request->effectiveSourceMode(PackageType::from($request->validated('type')))->value;
        // The owner is the organization the selected registries belong to; the request has
        // already refused a selection spanning more than one.
        $attributes['organization_id'] = $request->ownerOrganizationId();

        $package = Package::create($attributes);
        $package->groups()->sync($groupIds);

        // Only git-sourced packages have something to sync; publish-based packages (npm,
        // Python) are filled by pushing artifacts — skip the (doomed) sync job.
        if ($package->isGitSourced() && $package->repository_url !== null) {
            SyncPackage::dispatch($package);
        }

        // The PackagePicker creates packages inline via fetch and needs the
        // created package back to add it directly to the selection.
        if ($request->expectsJson()) {
            return response()->json([
                'id' => $package->id,
                'name' => $package->name,
                'type' => $package->type,
            ], 201);
        }

        // Explicitly to the detail page, not back(): the form now lives on its own
        // `admin/packages/create` page, and back() would return there — to a freshly emptied
        // form that renders no `flash.success`, so the operator saw a blank mask and no
        // confirmation. The detail page (not the index) is the right destination because
        // creation dispatches SyncPackage: sync status, sync errors and the resync action
        // all live there, and it renders the flash.
        return redirect()->route('admin.packages.show', $package)
            ->with('success', "Paket {$package->name} angelegt — Sync gestartet.");
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        $this->assertCanTouchPackage($package);

        // The detail page is now shown the redacted URL like every other reader, and its
        // form posts back whatever it was given. A redacted value byte-identical to the
        // redaction of what is stored is that echo — it means "unchanged", not "erase the
        // credential" — so it is resolved back to the stored value before validation.
        // Anything else redacted is still refused by NotRedactedCredentialUrl: an operator
        // who edited the path around a `***` has to supply the credential for the new URL,
        // and a client inventing a marker cannot overwrite a secret with it.
        $submitted = $request->input('repository_url');
        if (is_string($submitted)
            && CredentialUrl::isRedacted($submitted)
            && $submitted === CredentialUrl::redact($package->repository_url)) {
            $request->merge(['repository_url' => $package->repository_url]);
        }

        $data = $request->validate([
            'repository_url' => array_merge(
                [Rule::requiredIf($package->isGitSourced()), 'nullable'],
                RepositoryUrlRules::shape(),
                [new NotRedactedCredentialUrl],
            ),
            'repository_token' => ['nullable', 'string', 'max:500'],
            'git_credential_id' => ['nullable', 'uuid', 'exists:git_credentials,id'],
            'remove_token' => ['sometimes', 'boolean'],
        ], RepositoryUrlRules::messages());

        // `??` could not express "clear it". An emptied field arrives as null (the global
        // ConvertEmptyStringsToNull), which the null-coalesce read as "not submitted" and
        // answered by keeping the old URL — so removing a stale repository_url, the remedy
        // SyncPackage recommends to the operator of a publish-mode package, silently did
        // nothing. An absent key still means "unchanged".
        $url = array_key_exists('repository_url', $data) ? $data['repository_url'] : $package->repository_url;

        if (! empty($data['git_credential_id'])) {
            $credential = GitCredential::findOrFail($data['git_credential_id']);
            $this->assertAdministersOrg($credential->organization_id);
            $this->assertCredentialPermits($credential, $url);
        }

        // Moving the repository to another host while silently keeping the stored inline
        // token would ship that token to the new host on the next sync. The operator must
        // supply a token for the new host or drop the old one explicitly.
        $keepsInlineToken = $package->repository_token !== null
            && empty($data['repository_token'])
            && empty($data['remove_token']);
        if ($keepsInlineToken && ! $this->sameHost($url, $package->repository_url)) {
            throw ValidationException::withMessages([
                'repository_url' => 'Beim Wechsel des Repository-Hosts muss der gespeicherte Token neu gesetzt oder entfernt werden.',
            ]);
        }

        $update = [
            'repository_url' => $url,
            // An absent/empty credential clears the assignment.
            'git_credential_id' => $data['git_credential_id'] ?? null,
        ];
        if (! empty($data['repository_token'])) {
            $update['repository_token'] = $data['repository_token'];
        } elseif (! empty($data['remove_token'])) {
            $update['repository_token'] = null;
        }

        $package->update($update);

        // Re-sync git-sourced packages so a changed URL/token takes effect immediately.
        if ($package->isGitSourced() && $package->repository_url !== null) {
            SyncPackage::dispatch($package);
        }

        return back()->with('success', 'Paket aktualisiert.');
    }

    /**
     * Marks or unmarks a package as abandoned. Kept separate from update() rather than
     * folding into it: update() backs the narrow repository form and carries
     * credential-retarget logic that has nothing to do with abandonment, and widening it
     * would drag that logic into an unrelated write path.
     */
    public function abandonment(UpdatePackageAbandonmentRequest $request, Package $package): RedirectResponse
    {
        $this->assertCanTouchPackage($package);

        $abandoned = $request->boolean('abandoned');

        $package->update([
            // Re-marking an already-abandoned package must not reset the date — the banner
            // says "since", and an edit to the reason is not a new abandonment.
            'abandoned_at' => $abandoned ? ($package->abandoned_at ?? now()) : null,
            'replacement_package' => $abandoned ? $request->validated('replacement_package') : null,
            'abandonment_reason' => $abandoned ? $request->validated('abandonment_reason') : null,
        ]);

        return back()->with('success', $abandoned ? 'Paket als verwaist markiert.' : 'Markierung als verwaist entfernt.');
    }

    /**
     * Marks or unmarks a package as shared, gated by the `share-packages` ability
     * (super-admin always; a maintainer of the operator organization only once the
     * instance setting says so — see AppServiceProvider's gate definition).
     *
     * Deliberately not guarded by assertCanTouchPackage(): that helper checks the
     * package's organization against the caller's *active console scope*
     * (`OrgScope::ids()`, see the "active scope" vocabulary two methods above), which is
     * the wrong tool for an action whose entire purpose is to act across organization
     * scope — a super-admin (or a multi-org maintainer) who has the console scoped to one
     * customer organization is still meant to share a package the operator organization
     * owns, and `assertCanTouchPackage()` would reject that call on scope alone even
     * though `share-packages` authorizes it unconditionally. The ownership check below
     * (`$package->organization->is_operator`) is the actual security boundary for this
     * action: it is what refuses a customer-owned package regardless of who is calling,
     * and a caller-scope check adds nothing beyond it.
     *
     * The two directions are not symmetric. Setting the flag is what makes cross-organization
     * assignments legal, so nothing can be outstanding when it is set; clearing it can strand
     * assignments that were legal a moment earlier, so the clear is refused while any exist.
     */
    public function shared(Request $request, Package $package): RedirectResponse
    {
        abort_unless($request->user()?->can('share-packages'), 403);

        $data = $request->validate(['shared' => ['required', 'boolean']]);

        // Only a package the operator organization owns may be shared. A customer-owned
        // shared package would let that customer delete a dependency other customers'
        // builds resolve through it.
        if ($data['shared'] && ! $package->organization->is_operator) {
            throw ValidationException::withMessages([
                'shared' => 'Nur Pakete der Betreiber-Organisation können geteilt werden.',
            ]);
        }

        // Un-sharing is the reverse of an attach, and it can invalidate assignments that
        // were legitimate while the flag was set. The invariant at stake is the ownership
        // rule this application has held since packages became organization-owned: a
        // `group_package` row may only join a package to a registry of the organization
        // that owns it. `shared` is the single exception, so clearing it turns every
        // cross-organization row for this package back into a violation — one the ownership
        // enforcement refuses to migrate over — and, once resolution is scoped again, drops
        // the package out of those registries silently, so the name falls through to the
        // public index it was assigned there to pre-empt. Refuse and name the registries,
        // the way that enforcement does, rather than detaching rows on the operator's
        // behalf: the destructive step stays explicit.
        //
        // Every row counts here, expired or not — deliberately unlike SharedAssignment,
        // which asks what a registry SERVES and so skips expired assignments. The rule
        // above is about the row existing at all and says nothing about `available_until`,
        // so a lapsed row is exactly as much of a violation as a live one.
        if (! $data['shared']) {
            $foreign = $package->groups()
                ->where('groups.organization_id', '!=', $package->organization_id)
                ->orderBy('groups.name')
                ->pluck('groups.name');

            if ($foreign->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'shared' => $foreign->count() === 1
                        ? "Dieses Paket ist noch der Registry {$foreign->first()} einer anderen Organisation "
                            .'zugewiesen. Entfernen Sie es dort zuerst.'
                        : 'Dieses Paket ist noch Registrys anderer Organisationen zugewiesen: '
                            .$foreign->implode(', ').'. Entfernen Sie es dort zuerst.',
                ]);
            }
        }

        $package->update(['shared' => $data['shared']]);

        return back()->with('success', $data['shared']
            ? 'Paket wird jetzt für andere Organisationen freigegeben.'
            : 'Freigabe für andere Organisationen aufgehoben.');
    }

    public function destroy(Package $package): RedirectResponse
    {
        $this->assertCanTouchPackage($package);

        $package->delete();

        return back()->with('success', 'Paket gelöscht.');
    }

    /**
     * Mirrors Api\V1\PackageController::resync(): dispatching IS the point of this action,
     * so a package that cannot be synced is refused outright rather than redirected with a
     * success message that describes something which did not happen.
     */
    public function resync(Package $package): RedirectResponse
    {
        $this->assertCanTouchPackage($package);

        abort_if(! $package->isGitSourced(), 409, 'Dieses Paket ist nicht git-basiert und kann nicht synchronisiert werden.');

        SyncPackage::dispatch($package);

        return back()->with('success', 'Synchronisierung wurde eingereiht.');
    }

    /**
     * A stored git token is bound to exactly one host — pairing it with a repository
     * elsewhere would transmit the secret to that host as an Authorization header.
     */
    private function assertCredentialPermits(GitCredential $credential, ?string $url): void
    {
        if (! $credential->permits($url)) {
            throw ValidationException::withMessages([
                'repository_url' => $credential->hostMismatchMessage(),
            ]);
        }
    }

    /**
     * Whether two repository URLs point at the same place a token would be sent.
     *
     * `parse_url(..., PHP_URL_HOST)` discards the port by construction, so this compared
     * `https://gitlab.corp/x` and `https://gitlab.corp:9999/x` as equal — and
     * `GitAuth::origin()` scopes the Authorization header to scheme://host:port, so they are
     * not. A Maintainer who could bind a listener on another port of the repository host
     * therefore had the organization's PAT delivered to it, unchanged, on the next sync.
     * `GitCredential::permits()` was fixed for the managed column; this asks the same
     * question through the same helper so they cannot drift again.
     *
     * Two absent URLs are the same authority (nothing moved). One absent and one present is
     * not.
     */
    private function sameHost(?string $a, ?string $b): bool
    {
        return RepositoryAuthority::of($a) === RepositoryAuthority::of($b);
    }
}

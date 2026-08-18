<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Jobs\SyncPackage;
use App\Models\GitCredential;
use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PythonDist;
use App\Rules\NotRedactedCredentialUrl;
use App\Services\Package\PackageDependencies;
use App\Services\Registry\RegistryTypeService;
use App\Services\Scope\OrgScope;
use App\Services\Vcs\RepositoryProbe;
use App\Support\ActivityPresenter;
use App\Support\CredentialUrl;
use App\Support\RepositoryAuthority;
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

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $type = $request->query('type');
        $status = $request->query('status');
        $group = $request->query('group');

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
            ->latest()
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
            ]);

        return Inertia::render('admin/packages/Index', [
            'packages' => $packages,
            'groups' => $this->scopeGroupQuery(Group::query())->orderBy('name')->get(['id', 'name', 'slug']),
            'filters' => ['q' => $q, 'type' => $type, 'status' => $status, 'group' => $group],
            // Only instance-enabled registry types are offered when creating a package.
            'registryTypes' => app(RegistryTypeService::class)->globalTypes(),
            // Source-mode options per package type. npm has exactly one, so the create
            // dialog hides the selector for it rather than offering a rejected choice.
            'sourceModes' => collect(PackageType::cases())
                ->mapWithKeys(fn (PackageType $t): array => [$t->value => array_map(
                    fn (PackageSourceMode $m): array => ['value' => $m->value, 'label' => $m->label()],
                    PackageSourceMode::allowedFor($t)
                )])
                ->all(),
            // Managed git credentials the user may assign (never exposes the token).
            'gitCredentials' => GitCredential::whereIn('organization_id', $this->scopedOrgIds())
                ->orderBy('name')->get(['id', 'name', 'provider'])
                ->map(fn (GitCredential $c) => ['id' => $c->id, 'name' => $c->name, 'provider' => $c->provider->value]),
        ]);
    }

    public function show(Package $package, PackageDependencies $deps): Response
    {
        $this->assertCanTouchPackage($package);

        $package->load(['versions', 'groups:id,name,slug,organization_id']);
        $package->setRelation('versions', VersionOrder::sort($package->versions));

        // `assertCanTouchPackage()` only asserts that ONE of the package's registries is
        // reachable in the active scope. Serialising the whole relation therefore handed a
        // customer-org admin the id, name and slug of every *other* organization's registry
        // the package is shared into. The state needs a super-admin's deliberate cross-org
        // attach to arise at all, which is why this is small — but the caller has no business
        // reading it, so they get a count instead.
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
            ],
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
            'groups' => $visibleGroups->map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name, 'slug' => $g->slug])->values(),
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

    public function probe(Request $request, RepositoryProbe $probe): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(PackageType::class)],
            'repository_url' => ['required', 'string', 'max:500', 'url:https,ssh', 'starts_with:https://,ssh://'],
            'repository_token' => ['nullable', 'string', 'max:500'],
            'git_credential_id' => ['nullable', 'uuid', 'exists:git_credentials,id'],
        ]);

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

    public function store(StorePackageRequest $request): RedirectResponse|JsonResponse
    {
        // A package may only be attached to registries the user administers, so it can
        // never be slipped into another organization's registry.
        $groupIds = $request->validated('group_ids', []);
        foreach ($groupIds as $groupId) {
            $this->assertAdministersGroup(Group::findOrFail($groupId));
        }

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

        return back()->with('success', "Paket {$package->name} angelegt — Sync gestartet.");
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
            'repository_url' => [
                Rule::requiredIf($package->isGitSourced()),
                'nullable', 'string', 'max:500', new NotRedactedCredentialUrl,
                'url:https,ssh', 'starts_with:https://,ssh://',
            ],
            'repository_token' => ['nullable', 'string', 'max:500'],
            'git_credential_id' => ['nullable', 'uuid', 'exists:git_credentials,id'],
            'remove_token' => ['sometimes', 'boolean'],
        ]);

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

    public function destroy(Package $package): RedirectResponse
    {
        $this->assertCanTouchPackage($package);

        $package->delete();

        return back()->with('success', 'Paket gelöscht.');
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

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GitProvider;
use App\Enums\PackageType;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Models\GitCredential;
use App\Models\Organization;
use App\Services\Scope\OrgScope;
use App\Services\Vcs\RepositoryProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages reusable git access credentials (tokens), scoped to the organizations the user
 * administers. Tokens are write-only from the UI (encrypted at rest, never returned).
 */
class GitCredentialController extends Controller
{
    use ScopesToAdministeredOrgs;

    public function index(): Response
    {
        $scopedOrgIds = $this->scopedOrgIds();

        $own = GitCredential::with('organization:id,name')
            ->withCount(['packages', 'sharedOrganizations'])
            ->whereIn('organization_id', $scopedOrgIds)
            ->orderBy('name')->get()
            ->map(fn (GitCredential $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'provider' => $c->provider->value,
                'host' => $c->allowedHost(),
                'username' => $c->username,
                'organization' => $c->organization?->name,
                'organization_id' => $c->organization_id,
                'packages_count' => $c->packages_count,
                'last_used_at' => $c->last_used_at?->diffForHumans(),
                // Raw ISO timestamp for sorting only — `last_used_at` above is a relative
                // string ("vor 3 Tagen") that Date.parse cannot read, so the display value
                // and the sort value have to travel separately.
                'last_used_at_iso' => $c->last_used_at?->toIso8601String(),
                'is_own' => true,
                'badge' => $c->is_global ? 'global' : ($c->shared_organizations_count > 0 ? 'shared' : null),
            ]);

        // Credentials owned elsewhere but usable here — global, or explicitly shared to one
        // of the scoped organizations (see GitCredential::isUsableBy()). Listed read-only:
        // name, provider and host only — never the secret (`$hidden` keeps it out of every
        // serialisation regardless), no packages count or last-used timestamp (that is the
        // owner's bookkeeping, not a fact about this organization), and the Vue table shows
        // no edit/delete/test action for a row this admin does not own.
        $foreign = GitCredential::with('organization:id,name')
            ->whereNotIn('organization_id', $scopedOrgIds)
            ->where(fn ($q) => $q->where('is_global', true)
                ->orWhereHas('sharedOrganizations', fn ($s) => $s->whereIn('organizations.id', $scopedOrgIds)))
            ->orderBy('name')->get()
            ->map(fn (GitCredential $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'provider' => $c->provider->value,
                'host' => $c->allowedHost(),
                'username' => null,
                'organization' => $c->organization?->name,
                'organization_id' => $c->organization_id,
                'packages_count' => null,
                'last_used_at' => null,
                'last_used_at_iso' => null,
                'is_own' => false,
                'badge' => $c->is_global ? 'global' : 'shared',
            ]);

        return Inertia::render('admin/git-credentials/Index', [
            'credentials' => $own->concat($foreign)->values(),
            'organizations' => app(OrgScope::class)->organizations(),
            'providers' => GitProvider::metadata(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/git-credentials/Create', [
            'organizations' => $this->scopedOrganizationOptions(),
            'providers' => GitProvider::metadata(),
            // Only ever useful once an operator-owned credential exists to attach shares
            // to, so it is withheld from anyone who could not possibly create one — a
            // customer-org admin's create page never receives the full organization
            // directory just to render a control it will never show.
            'shareableOrganizations' => Auth::user()?->administersOperatorOrganization()
                ? $this->shareableOrganizations()
                : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'provider' => ['required', Rule::enum(GitProvider::class)],
            'host' => $this->hostRules($request),
            'username' => ['nullable', 'string', 'max:190'],
            'token' => ['required', 'string', 'max:500'],
            // Sharing is an operator-only capability, decided below against the resolved
            // owner — never against the request alone. A customer submitting these gets
            // them silently dropped rather than a validation error, since the Form never
            // renders the controls outside the operator organization in the first place.
            'is_global' => ['sometimes', 'boolean'],
            'shared_organization_ids' => ['sometimes', 'array'],
            'shared_organization_ids.*' => ['uuid', 'exists:organizations,id'],
        ]);

        $ownerOrganizationId = $this->resolveCreationOrg($data['organization_id'] ?? null);
        $ownerOrganization = Organization::findOrFail($ownerOrganizationId);

        $credential = GitCredential::create([
            'organization_id' => $ownerOrganizationId,
            'name' => $data['name'],
            'provider' => $data['provider'],
            'host' => $this->resolveHost($data),
            'username' => $data['username'] ?? null,
            'token' => $data['token'],
            'is_global' => $ownerOrganization->is_operator && (bool) ($data['is_global'] ?? false),
        ]);

        if ($ownerOrganization->is_operator) {
            $credential->sharedOrganizations()->sync($this->sharedOrganizationIds($data, $ownerOrganizationId));
        }

        // Explicitly to the index, not back(): the form now lives on its own
        // `admin/git-credentials/create` page, and back() would return there — to a freshly
        // emptied form that renders no `flash.success`. The index shows the new row and the
        // flash.
        return redirect()->route('admin.git-credentials.index')->with('success', 'Git-Token gespeichert.');
    }

    public function edit(GitCredential $gitCredential): Response
    {
        $this->assertAdministersOrg($gitCredential->organization_id);

        $isOperatorCredential = (bool) $gitCredential->organization?->is_operator;

        // Loaded fresh from the record, not from the index listing's mapped row: this page
        // is reached directly (URL, bookmark, back button), so it cannot rely on anything
        // the listing already had in memory. The stored token itself is deliberately
        // excluded — it must never travel back to the browser to pre-fill a field; the
        // edit page's token input starts blank and only a filled value replaces it,
        // exactly like the dialog this page replaces.
        return Inertia::render('admin/git-credentials/Edit', [
            'credential' => [
                'id' => $gitCredential->id,
                'name' => $gitCredential->name,
                'provider' => $gitCredential->provider->value,
                'host' => $gitCredential->host,
                'username' => $gitCredential->username,
                'organization_id' => $gitCredential->organization_id,
                'is_global' => $gitCredential->is_global,
                // Only a customer organization is ever a share target (see
                // shareableOrganizations()), so this is meaningless — and withheld — for a
                // customer-owned credential regardless of what the pivot happens to hold.
                'shared_organization_ids' => $isOperatorCredential
                    ? $gitCredential->sharedOrganizations()->pluck('organizations.id')->all()
                    : [],
            ],
            'organizations' => $this->scopedOrganizationOptions(),
            'providers' => GitProvider::metadata(),
            'shareableOrganizations' => $isOperatorCredential ? $this->shareableOrganizations() : [],
        ]);
    }

    public function update(Request $request, GitCredential $gitCredential): RedirectResponse
    {
        $this->assertAdministersOrg($gitCredential->organization_id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'provider' => ['required', Rule::enum(GitProvider::class)],
            'host' => $this->hostRules($request),
            'username' => ['nullable', 'string', 'max:190'],
            // Blank = keep the existing token.
            'token' => ['nullable', 'string', 'max:500'],
            'is_global' => ['sometimes', 'boolean'],
            'shared_organization_ids' => ['sometimes', 'array'],
            'shared_organization_ids.*' => ['uuid', 'exists:organizations,id'],
        ]);

        // Retargeting a credential at a different host while keeping the stored token
        // would hand that token to the new host — the secret must be re-entered, which
        // only someone who already knows it can do.
        $host = $this->resolveHost($data);
        if ($host !== $gitCredential->allowedHost() && empty($data['token'])) {
            throw ValidationException::withMessages([
                'host' => 'Beim Wechsel des Hosts muss der Token neu eingegeben werden.',
            ]);
        }

        $gitCredential->fill([
            'name' => $data['name'],
            'provider' => $data['provider'],
            'host' => $host,
            'username' => $data['username'] ?? null,
        ]);
        if (! empty($data['token'])) {
            $gitCredential->token = $data['token'];
        }

        // Sharing is operator-only: a customer-owned credential's is_global flag and share
        // set are never touched by this endpoint, even if a crafted request includes them,
        // because the Form only ever renders those controls for an operator-owned one.
        $isOperatorCredential = (bool) $gitCredential->organization?->is_operator;
        if ($isOperatorCredential && array_key_exists('is_global', $data)) {
            $gitCredential->is_global = (bool) $data['is_global'];
        }

        $gitCredential->save();

        if ($isOperatorCredential && array_key_exists('shared_organization_ids', $data)) {
            $gitCredential->sharedOrganizations()->sync(
                $this->sharedOrganizationIds($data, $gitCredential->organization_id)
            );
        }

        return back()->with('success', 'Git-Token aktualisiert.');
    }

    public function destroy(GitCredential $gitCredential): RedirectResponse
    {
        $this->assertAdministersOrg($gitCredential->organization_id);

        $gitCredential->delete();

        return back()->with('success', 'Git-Token gelöscht.');
    }

    /** Verifies the credential can reach a given repository (git ls-remote). */
    public function test(Request $request, GitCredential $gitCredential, RepositoryProbe $probe): JsonResponse
    {
        $this->assertAdministersOrg($gitCredential->organization_id);

        $data = $request->validate([
            'repository_url' => ['required', 'string', 'max:500', 'url:https', 'starts_with:https://'],
        ]);

        // The token may only ever be sent to the host the credential is bound to.
        if (! $gitCredential->permits($data['repository_url'])) {
            throw ValidationException::withMessages([
                'repository_url' => $gitCredential->hostMismatchMessage(),
            ]);
        }

        $result = $probe->probe(
            PackageType::Composer,
            $data['repository_url'],
            $gitCredential->token,
            $gitCredential->provider,
            $gitCredential->username,
        );

        return response()->json(['ok' => $result['ok'], 'error' => $result['error'] ?? null]);
    }

    /**
     * A self-hosted ("generic") credential has no canonical host, so it must name one;
     * for the known providers the host defaults to that provider's own.
     *
     * @return array<int, string>
     */
    private function hostRules(Request $request): array
    {
        $required = GitProvider::tryFrom((string) $request->input('provider')) === GitProvider::Generic;

        // An optional port is part of the binding: permits() compares the whole authority,
        // so a self-hosted git server on a non-default port has to be nameable here.
        return [$required ? 'required' : 'nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9._-]+(:\d{1,5})?$/'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveHost(array $data): ?string
    {
        $host = strtolower(trim((string) ($data['host'] ?? '')));

        return $host !== '' ? $host : GitProvider::from((string) $data['provider'])->defaultHost();
    }

    /**
     * The organizations reachable from the create/edit page, each carrying `is_operator` so
     * the Form's is_global/sharing controls can decide whether they apply to the currently
     * targeted organization — only ever true for an operator-owned credential.
     *
     * @return array<int, array{id: string, name: string, is_operator: bool}>
     */
    private function scopedOrganizationOptions(): array
    {
        $scopedOrgIds = collect(app(OrgScope::class)->organizations())->pluck('id');

        return Organization::whereIn('id', $scopedOrgIds)->orderBy('name')
            ->get(['id', 'name', 'is_operator'])
            ->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name, 'is_operator' => $o->is_operator])
            ->all();
    }

    /**
     * Every non-operator organization — the possible share targets for an operator-owned
     * credential. Sharing only ever runs operator-to-customer: a customer-owned credential
     * cannot be shared at all (store()/update() drop the fields outright unless the owner is
     * the operator organization), so no customer organization is ever itself a valid target.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function shareableOrganizations(): array
    {
        return Organization::query()->where('is_operator', false)->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name])
            ->all();
    }

    /**
     * The submitted share list, deduplicated and with the owner's own organization dropped
     * (sharing a credential to the organization that already owns it grants nothing —
     * isUsableBy() already answers yes for the owner). `sync()` would tolerate either on its
     * own, but keeping the pivot free of both makes the stored set match what the Form
     * actually offers, not a superset of it.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function sharedOrganizationIds(array $data, string $ownerOrganizationId): array
    {
        return array_values(array_diff(array_unique($data['shared_organization_ids'] ?? []), [$ownerOrganizationId]));
    }
}

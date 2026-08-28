<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UpstreamPolicy;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUpstreamRequest;
use App\Http\Requests\Admin\UpdateUpstreamRequest;
use App\Models\Group;
use App\Models\Upstream;
use App\Support\CredentialUrl;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UpstreamController extends Controller
{
    use ScopesToAdministeredOrgs;

    public function index(): Response
    {
        // Upstreams hang off registries — scope both the listing and the group picker to
        // the registries of the organizations in the active scope.
        $groupIds = $this->scopeGroupQuery(Group::query())->pluck('id');

        return Inertia::render('admin/upstreams/Index', [
            'upstreams' => Upstream::with(['group:id,name', 'allowedPackages'])->whereIn('group_id', $groupIds)->latest()->get()
                ->map(fn (Upstream $u) => [
                    'id' => $u->id,
                    'group' => $u->group?->name,
                    'group_id' => $u->group_id,
                    'type' => $u->type,
                    // Redacted: `url` is the supported place to put a mirror's Basic-auth
                    // credential (see CredentialUrl), and the listing is reachable by any
                    // Maintainer of the organization, not just the Admin who entered it.
                    'url' => CredentialUrl::redact($u->url),
                    'policy' => $u->policy,
                    'priority' => $u->priority,
                    'enabled' => $u->enabled,
                    'has_auth' => (bool) $u->auth_token,
                    'allowed_packages' => $u->allowedPackages->pluck('name'),
                ]),
            'groups' => $this->scopeGroupQuery(Group::query())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/upstreams/Create', [
            'groups' => $this->scopeGroupQuery(Group::query())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreUpstreamRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $group = Group::findOrFail($data['group_id']);
        $this->assertAdministersGroup($group);

        $upstream = Upstream::create([
            'group_id' => $group->id,
            'type' => $data['type'],
            'url' => $data['url'],
            'policy' => $data['policy'],
            'auth_token' => $data['auth_token'] ?? null ?: null,
            'priority' => $data['priority'] ?? 0,
        ]);

        foreach ($data['allowed_packages'] ?? [] as $name) {
            $upstream->allowedPackages()->create(['name' => $name]);
        }

        return back()->with('success', 'Upstream erstellt.');
    }

    public function edit(Upstream $upstream): Response
    {
        $this->assertAdministersOrg($upstream->group?->organization_id);

        // Loaded fresh from the record, not from the index listing's mapped row: this page
        // is reached directly (URL, bookmark, back button), so it cannot rely on anything
        // the listing already had in memory. `auth_token` itself never leaves the server —
        // only `has_auth`, exactly like the index listing. `url` is redacted the same way
        // too: it legitimately carries `user:pass@host` for a Basic-auth mirror, and the
        // form only ever needs it verbatim back when the operator actually changes it —
        // see UpdateUpstreamRequest, which resolves an unchanged, still-redacted echo back
        // to the stored value before validation.
        $upstream->loadMissing(['group:id,name', 'allowedPackages']);

        return Inertia::render('admin/upstreams/Edit', [
            'upstream' => [
                'id' => $upstream->id,
                'group_id' => $upstream->group_id,
                'type' => $upstream->type,
                'url' => CredentialUrl::redact($upstream->url),
                'policy' => $upstream->policy,
                'priority' => $upstream->priority,
                'has_auth' => (bool) $upstream->auth_token,
                'allowed_packages' => $upstream->allowedPackages->pluck('name'),
            ],
            'groups' => $this->scopeGroupQuery(Group::query())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateUpstreamRequest $request, Upstream $upstream): RedirectResponse
    {
        $this->assertAdministersOrg($upstream->group?->organization_id);

        $data = $request->validated();

        $update = [
            'type' => $data['type'],
            'url' => $data['url'],
            'policy' => $data['policy'],
            'priority' => $data['priority'] ?? 0,
        ];
        if (array_key_exists('enabled', $data)) {
            $update['enabled'] = (bool) $data['enabled'];
        }
        // Token: a value replaces it, `remove_auth_token` clears it, blank keeps it —
        // so editing an upstream never forces re-entering the mirror credential.
        if (! empty($data['auth_token'])) {
            $update['auth_token'] = $data['auth_token'];
        } elseif (! empty($data['remove_auth_token'])) {
            $update['auth_token'] = null;
        }

        $upstream->update($update);

        // Replace the allowlist wholesale (only meaningful in strict mode).
        $upstream->allowedPackages()->delete();
        if (($data['policy'] ?? null) === UpstreamPolicy::Strict->value) {
            foreach ($data['allowed_packages'] ?? [] as $name) {
                $upstream->allowedPackages()->create(['name' => $name]);
            }
        }

        return back()->with('success', 'Upstream aktualisiert.');
    }

    public function destroy(Upstream $upstream): RedirectResponse
    {
        $this->assertAdministersOrg($upstream->group?->organization_id);

        $upstream->delete();

        return back()->with('success', 'Upstream gelöscht.');
    }
}

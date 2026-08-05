<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUpstreamRequest;
use App\Models\Group;
use App\Models\Upstream;
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
                    'url' => $u->url,
                    'policy' => $u->policy,
                    'priority' => $u->priority,
                    'enabled' => $u->enabled,
                    'has_auth' => (bool) $u->auth_token,
                    'allowed_packages' => $u->allowedPackages->pluck('name'),
                ]),
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

    public function destroy(Upstream $upstream): RedirectResponse
    {
        $this->assertAdministersOrg($upstream->group?->organization_id);

        $upstream->delete();

        return back()->with('success', 'Upstream gelöscht.');
    }
}

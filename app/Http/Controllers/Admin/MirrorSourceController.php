<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PackageType;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MirrorSourceRequest;
use App\Models\MirrorSource;
use App\Models\Organization;
use App\Services\Scope\OrgScope;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages reusable mirror sources — organization-scoped pointers at a foreign Composer/npm/
 * PyPI registry that packages can populate from. The org-level counterpart to
 * GitCredentialController, minus sharing: a mirror source belongs to exactly one
 * organization and is never usable by another (see MirrorSource's docblock). The auth token
 * is write-only from the UI (encrypted at rest, never returned).
 */
class MirrorSourceController extends Controller
{
    use ScopesToAdministeredOrgs;

    public function index(): Response
    {
        $scopedOrgIds = $this->scopedOrgIds();

        $sources = MirrorSource::with('organization:id,name')
            ->withCount('packages')
            ->whereIn('organization_id', $scopedOrgIds)
            ->orderBy('name')->get()
            ->map(fn (MirrorSource $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type->value,
                'url' => $s->url,
                'organization' => $s->organization?->name,
                'organization_id' => $s->organization_id,
                'packages_count' => $s->packages_count,
                'last_used_at' => $s->last_used_at?->diffForHumans(),
                // Raw ISO timestamp for sorting only — `last_used_at` above is a relative
                // string ("vor 3 Tagen") that Date.parse cannot read.
                'last_used_at_iso' => $s->last_used_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/mirror-sources/Index', [
            'sources' => $sources,
            'organizations' => app(OrgScope::class)->organizations(),
            'types' => $this->typeOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/mirror-sources/Create', [
            'organizations' => $this->scopedOrganizationOptions(),
            'types' => $this->typeOptions(),
        ]);
    }

    public function store(MirrorSourceRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $ownerOrganizationId = $this->resolveCreationOrg($data['organization_id'] ?? null);

        MirrorSource::create([
            'organization_id' => $ownerOrganizationId,
            'name' => $data['name'],
            'type' => $data['type'],
            'url' => $data['url'],
            'auth_token' => $data['auth_token'] ?? null ?: null,
        ]);

        // Explicitly to the index, not back(): the form lives on its own
        // `admin/mirror-sources/create` page, and back() would return there — to a freshly
        // emptied form that renders no `flash.success`. The index shows the new row and the
        // flash.
        return redirect()->route('admin.mirror-sources.index')->with('success', 'Mirror-Quelle gespeichert.');
    }

    public function edit(MirrorSource $mirrorSource): Response
    {
        $this->assertAdministersOrg($mirrorSource->organization_id);

        // The stored token itself is deliberately excluded — it must never travel back to
        // the browser to pre-fill a field; the edit page's token input starts blank and
        // only a filled value replaces it.
        return Inertia::render('admin/mirror-sources/Edit', [
            'source' => [
                'id' => $mirrorSource->id,
                'name' => $mirrorSource->name,
                'type' => $mirrorSource->type->value,
                'url' => $mirrorSource->url,
                'organization_id' => $mirrorSource->organization_id,
            ],
            'organizations' => $this->scopedOrganizationOptions(),
            'types' => $this->typeOptions(),
        ]);
    }

    public function update(MirrorSourceRequest $request, MirrorSource $mirrorSource): RedirectResponse
    {
        $this->assertAdministersOrg($mirrorSource->organization_id);

        $data = $request->validated();

        $mirrorSource->fill([
            'name' => $data['name'],
            'type' => $data['type'],
            'url' => $data['url'],
        ]);
        if (! empty($data['auth_token'])) {
            $mirrorSource->auth_token = $data['auth_token'];
        }
        $mirrorSource->save();

        return back()->with('success', 'Mirror-Quelle aktualisiert.');
    }

    public function destroy(MirrorSource $mirrorSource): RedirectResponse
    {
        $this->assertAdministersOrg($mirrorSource->organization_id);

        // Deletion is allowed even when packages still reference this source: the foreign
        // key is nullOnDelete (see the mirror_sources migration), so a referencing package
        // is orphaned rather than the delete being refused or cascading. The flash makes
        // that consequence explicit rather than silent.
        $mirrorSource->delete();

        return back()->with(
            'success',
            'Mirror-Quelle gelöscht. Pakete, die diese Quelle genutzt haben, schlagen bei ihrer nächsten Synchronisierung fehl, bis sie eine neue Quelle erhalten.'
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(): array
    {
        return array_values(array_filter(
            PackageType::metadata(),
            fn (array $type) => $type['value'] !== PackageType::Docker->value,
        ));
    }

    /**
     * The organizations reachable from the create/edit page.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function scopedOrganizationOptions(): array
    {
        $scopedOrgIds = collect(app(OrgScope::class)->organizations())->pluck('id');

        return Organization::whereIn('id', $scopedOrgIds)->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name])
            ->all();
    }
}

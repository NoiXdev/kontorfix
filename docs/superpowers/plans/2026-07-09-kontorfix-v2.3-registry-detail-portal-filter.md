# Kontorfix v2.3 – Registry-Detailseite + Portal-Paketfilter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eine Detailseite pro Registry (Gruppe) im Admin — mit zugewiesenen Paketen, Domains, Upstreams, Tokens und Setup-Snippet — und Filter/Suche in der Portal-Paketliste, damit Kunden ihre Pakete durchsuchen können (Parität zum Admin-v2.2).

**Architecture:** `Group` trägt bereits alle Relationen (`organization`, `packages`, `domains`, `tokens`, `upstreams`). Ein operator-gated `GroupController@show` sammelt sie + baut das Setup-Snippet über den vorhandenen `SetupSnippetBuilder`/`RegistryUrl`. Der Portal-`RegistryController@show` bekommt serverseitige Filter (`q`, `type`) auf die Paketliste der Registry (ACL bleibt: nur eigene Org).

**Tech Stack:** Laravel 12, Inertia v2 + Vue 3, Postgres (`ilike`), Pest, Pint, Larastan L6.

---

## File Structure

- Modify `app/Http/Controllers/Admin/GroupController.php` — `show`.
- Modify `routes/web.php` — `groups.show` in der operator-`role:admin,maintainer`-Gruppe.
- Create `resources/js/pages/admin/groups/Show.vue`; modify `resources/js/pages/admin/groups/Index.vue` (Namen verlinken).
- Modify `app/Http/Controllers/Portal/RegistryController.php` — Filter in `show`.
- Modify `resources/js/pages/portal/Registry.vue` — Filterleiste.
- Tests: `tests/Feature/Admin/GroupDetailTest.php`, `tests/Feature/Portal/PortalPackageFilterTest.php`, E2E.

---

### Task RG1: Admin-Registry-Detailseite

**Files:** `app/Http/Controllers/Admin/GroupController.php`, `routes/web.php`, `resources/js/pages/admin/groups/Show.vue`, `resources/js/pages/admin/groups/Index.vue`, Test `tests/Feature/Admin/GroupDetailTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Upstream;
use App\Models\User;

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

it('shows a registry with packages, domains, upstreams, tokens and a setup snippet', function () {
    $group = Group::factory()->for(Organization::factory())->create(['name' => 'Kadenz', 'slug' => 'kadenz']);
    $pkg = Package::factory()->create(['name' => 'acme/widget']);
    $group->packages()->attach($pkg);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    Upstream::factory()->for($group)->create();

    $this->actingAs($this->admin)->get("/admin/groups/{$group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/groups/Show')
            ->where('group.name', 'Kadenz')
            ->has('packages', 1)->where('packages.0.name', 'acme/widget')
            ->has('domains', 1)->where('domains.0', 'packages.kadenz.test')
            ->has('upstreams', 1)
            ->has('setup.composer'));
});

it('is operator-gated', function () {
    $group = Group::factory()->for(Organization::factory())->create();
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    $this->actingAs($custAdmin)->get("/admin/groups/{$group->id}")->assertForbidden();
});
```
Prüfe die `Upstream`-/`Domain`-Factory: falls `->for($group)` nicht greift, `->create(['group_id' => $group->id, ...])` verwenden; Upstream braucht evtl. `type`/`url`/`policy` (Factory setzt Defaults — sonst minimal ergänzen).

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3:** `GroupController@show(Group $group, \App\Services\Registry\SetupSnippetBuilder $snippets): Response`:
```php
$group->load(['organization:id,name', 'domains:id,group_id,hostname', 'upstreams', 'tokens.group:id,name']);

return Inertia::render('admin/groups/Show', [
    'group' => [
        'id' => $group->id, 'name' => $group->name, 'slug' => $group->slug,
        'public' => $group->public, 'organization' => $group->organization?->name,
    ],
    'packages' => $group->packages()->orderBy('name')->get(['packages.id', 'name', 'type', 'sync_status'])
        ->map(fn (Package $p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type->value, 'sync_status' => $p->sync_status->value]),
    'domains' => $group->domains->pluck('hostname'),
    'upstreams' => $group->upstreams->map(fn (Upstream $u) => ['id' => $u->id, 'type' => $u->type->value, 'url' => $u->url, 'policy' => $u->policy->value]),
    'tokens' => $group->tokens->map(fn (RegistryToken $t) => ['id' => $t->id, 'name' => $t->name, 'ability' => $t->ability->value]),
    'setup' => $snippets->for($group),
]);
```
(Imports `Package`, `Upstream`, `RegistryToken`, `Response` ergänzen. `SetupSnippetBuilder::for()` liefert `{composer,auth,npm}`. Achte auf ambige Spaltennamen bei `packages()->get([...])` → mit `packages.id` qualifizieren.)

- [ ] **Step 4:** Route in der operator-`role:admin,maintainer`-Gruppe — SEPARAT als GET (die groups-resource hat kein show):
```php
Route::get('groups/{group}', [Admin\GroupController::class, 'show'])->name('groups.show');
```

- [ ] **Step 5: Vue** `admin/groups/Show.vue`: Kopf (Name, `/r/{slug}`, public-Badge, Org); Abschnitte „Pakete" (Liste mit StatusPill, verlinkt auf `admin.packages.show`), „Domains", „Upstreams" (type/url/policy), „Tokens" (name/ability), „Einrichtung" (Setup-Snippets composer/auth/npm in `<pre>` mit Copy-Button, wie im Portal). In `admin/groups/Index.vue` den Gruppennamen als `Link` auf `route('admin.groups.show', group.id)` rendern.

- [ ] **Step 6:** Tests grün (auch bestehende Group-Tests); Build; Pint + PHPStan.
- [ ] **Step 7:** Commit `feat: admin registry detail page (packages, domains, upstreams, tokens, setup)`.

---

### Task RG2: Portal-Paketliste filtern

**Files:** `app/Http/Controllers/Portal/RegistryController.php`, `resources/js/pages/portal/Registry.vue`, Test `tests/Feature/Portal/PortalPackageFilterTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
    $this->org = Organization::factory()->create();
    $this->member = User::factory()->for($this->org)->create(['role' => UserRole::Member]);
    $this->group = Group::factory()->for($this->org)->create(['slug' => 'acme']);
    $a = Package::factory()->create(['name' => 'acme/alpha', 'type' => 'composer']);
    $b = Package::factory()->create(['name' => 'beta/widget', 'type' => 'npm']);
    $this->group->packages()->attach([$a->id, $b->id]);
});

it('filters the portal package list by name and type', function () {
    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}?q=acme")
        ->assertInertia(fn ($p) => $p->has('packages', 1)->where('packages.0.name', 'acme/alpha'));

    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}?type=npm")
        ->assertInertia(fn ($p) => $p->has('packages', 1)->where('packages.0.name', 'beta/widget'));

    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}?q=acme&type=composer")
        ->assertInertia(fn ($p) => $p->where('filters.q', 'acme')->where('filters.type', 'composer'));
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3:** `RegistryController@show` — die bestehende Paket-Query um Filter erweitern (ACL/Org-Scoping unverändert lassen; nur die `$group->packages()`-Query filtern):
```php
$q = trim((string) $request->query('q', ''));
$type = $request->query('type');

$packages = $group->packages()
    ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
    ->when(in_array($type, ['composer', 'npm'], true), fn ($query) => $query->where('type', $type))
    ->orderBy('name')->get(/* bestehende Felder */);
```
Ergänze `'filters' => ['q' => $q, 'type' => $type]` in den Inertia-Props. Bestehende `packages`/`tokens`/`snippets`-Props unverändert (nur die Query gefiltert).

- [ ] **Step 4: Vue** `portal/Registry.vue` — eine schlanke Filterleiste über der Paketliste: Text-Input (`q`, debounced) + Typ-Select (Alle/composer/npm), `router.get(route('portal.registries.show', registry.id), { q, type }, { preserveState: true, preserveScroll: true, replace: true })`, Initialwerte aus `filters`. „Zurücksetzen" bei aktivem Filter.

- [ ] **Step 5:** Tests grün (auch bestehende Portal-Tests — die Filter/`packages`-Props sind additiv/kompatibel); Build; Pint + PHPStan.
- [ ] **Step 6:** Commit `feat: search and filter the customer portal package list`.

---

### Task RG3: E2E + volle Suite

- [ ] **Step 1:** `tests/Feature/RegistryDetailPortalFilterEndToEndTest.php` — Admin öffnet Registry-Detail (Pakete/Domains/Setup sichtbar); Kunde filtert seine Portal-Paketliste (q/type) und sieht die erwartete Teilmenge; Cross-Org bleibt dicht (fremde Registry-Detail im Portal → 403).
- [ ] **Step 2:** Volle Suite `ddev exec vendor/bin/pest` → alle grün (Gesamtzahl melden). `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run build`, `ddev exec npm run lint:check`.
- [ ] **Step 3:** Commit `test: end-to-end registry detail and portal package filter`.

---

## Self-Review

- **Deckt „gleiches gilt für fast alles":** Registry-Detailseite (Admin) analog zur Paket-Detailseite; Filter/Suche jetzt auch im Kunden-Portal (Parität zu Admin-v2.2).
- **Sicherheit:** Registry-Detail operator-gated (Route-Gruppe); Portal-Filter ändert NICHTS an der Org-ACL (nur die bereits gescopte `$group->packages()`-Query wird gefiltert); `ilike` mit escaptem Wildcard; Typ gegen Whitelist. Kein Token/Secret-Leak (Tokens nur name/ability).
- **Verschoben/Follow-up:** globale Suche zusätzlich über Registries/Kunden; Bearbeiten von Registry-Feldern auf der Detailseite (aktuell read-only + Setup); OCI (Phase 2).

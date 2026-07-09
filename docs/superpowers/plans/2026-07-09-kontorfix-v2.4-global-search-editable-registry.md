# Kontorfix v2.4 – Globale Suche (breiter) + editierbare Registry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Command-Palette (⌘K) durchsucht nicht mehr nur Pakete, sondern auch Registries und Kunden und springt zur jeweiligen Detailseite; und eine Registry lässt sich nachträglich bearbeiten (Name, öffentlich/privat) — der Slug bleibt als stabiler Endpunkt-Identifier unveränderlich.

**Architecture:** Ein operator-gated `GlobalSearchController` liefert kategorisierte Treffer (`packages`/`registries`/`customers`) über `ilike`-Namenssuche. Die vorhandene `CommandPalette.vue` rendert Sektionen und navigiert nach Typ (`admin.packages.show` / `admin.groups.show` / `admin.organizations.show`). `GroupController@update` erlaubt Name + `public` (nicht slug), Formular auf der Registry-Detailseite.

**Tech Stack:** Laravel 12, Inertia v2 + Vue 3, Postgres (`ilike`), Pest, Pint, Larastan L6.

---

## File Structure

- Create `app/Http/Controllers/Admin/GlobalSearchController.php`; modify `routes/web.php` (operator-Gruppe).
- Modify `resources/js/components/kontorfix/CommandPalette.vue` — kategorisierte Ergebnisse.
- Create `app/Http/Requests/Admin/UpdateGroupRequest.php`; modify `app/Http/Controllers/Admin/GroupController.php` (`update`), `routes/web.php`, `resources/js/pages/admin/groups/Show.vue` (Edit-Formular).
- Tests: `tests/Feature/Admin/GlobalSearchTest.php`, `tests/Feature/Admin/GroupUpdateTest.php`, E2E.

---

### Task SC1: Globale Suche über Pakete, Registries und Kunden

**Files:** `app/Http/Controllers/Admin/GlobalSearchController.php`, `routes/web.php`, `resources/js/components/kontorfix/CommandPalette.vue`, Test `tests/Feature/Admin/GlobalSearchTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

it('searches packages, registries and customers by name', function () {
    Package::factory()->create(['name' => 'acme/widget']);
    Group::factory()->for(Organization::factory())->create(['name' => 'Acme Registry']);
    Organization::factory()->create(['name' => 'Acme GmbH', 'is_operator' => false]);

    $res = $this->actingAs($this->admin)->getJson('/admin/search?q=acme');
    $res->assertOk();

    expect(collect($res->json('packages'))->pluck('name'))->toContain('acme/widget');
    expect(collect($res->json('registries'))->pluck('name'))->toContain('Acme Registry');
    expect(collect($res->json('customers'))->pluck('name'))->toContain('Acme GmbH');
});

it('is operator-gated', function () {
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    $this->actingAs($custAdmin)->getJson('/admin/search?q=x')->assertForbidden();
});

it('returns empty categories for a blank query', function () {
    $res = $this->actingAs($this->admin)->getJson('/admin/search?q=');
    $res->assertOk()->assertJson(['packages' => [], 'registries' => [], 'customers' => []]);
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: Implement** `GlobalSearchController` (invokable):
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json(['packages' => [], 'registries' => [], 'customers' => []]);
        }

        $like = '%'.addcslashes($q, '%_\\').'%';

        return response()->json([
            'packages' => Package::where('name', 'ilike', $like)->orderBy('name')->limit(5)
                ->get(['id', 'name', 'type'])->map(fn (Package $p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type->value]),
            'registries' => Group::where('name', 'ilike', $like)->orderBy('name')->limit(5)
                ->get(['id', 'name', 'slug'])->map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name, 'slug' => $g->slug]),
            'customers' => Organization::where('name', 'ilike', $like)->orderBy('name')->limit(5)
                ->get(['id', 'name', 'is_operator'])->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name, 'is_operator' => $o->is_operator]),
        ]);
    }
}
```

- [ ] **Step 4:** Route in der operator-`role:admin,maintainer`-Gruppe:
```php
Route::get('search', Admin\GlobalSearchController::class)->name('search');
```

- [ ] **Step 5: `CommandPalette.vue`** umstellen: statt `admin.package-search` jetzt `admin.search` abrufen; Ergebnisse in drei Sektionen („Pakete", „Registries", „Kunden") mit je einem Icon/Badge rendern; Tastatur-Navigation über die flache Reihenfolge aller Treffer; Enter/Klick navigiert typabhängig: Paket → `route('admin.packages.show', id)`, Registry → `route('admin.groups.show', id)`, Kunde → `route('admin.organizations.show', id)`. Leerer Query/keine Treffer wie bisher. CSRF-/Fetch-Muster unverändert.

- [ ] **Step 6:** Tests grün; Build; Lint; Pint + PHPStan (falls PHP).
- [ ] **Step 7:** Commit `feat: global search across packages, registries and customers`.

---

### Task SC2: Registry bearbeiten (Name, öffentlich/privat)

**Files:** `app/Http/Requests/Admin/UpdateGroupRequest.php`, `app/Http/Controllers/Admin/GroupController.php`, `routes/web.php`, `resources/js/pages/admin/groups/Show.vue`, Test `tests/Feature/Admin/GroupUpdateTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

it('updates a registry name and visibility but never the slug', function () {
    $group = Group::factory()->for(Organization::factory())->create(['name' => 'Alt', 'slug' => 'kadenz', 'public' => false]);

    $this->actingAs($this->admin)->put("/admin/groups/{$group->id}", ['name' => 'Neu', 'public' => true, 'slug' => 'gehackt'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $fresh = $group->fresh();
    expect($fresh->name)->toBe('Neu')->and($fresh->public)->toBeTrue()
        ->and($fresh->slug)->toBe('kadenz'); // Slug unverändert
});

it('is operator-gated', function () {
    $group = Group::factory()->for(Organization::factory())->create();
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    $this->actingAs($custAdmin)->put("/admin/groups/{$group->id}", ['name' => 'X'])->assertForbidden();
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: `UpdateGroupRequest`** — `authorize(): true`; `prepareForValidation` setzt `public` via boolean; Regeln: `name` required string max:190; `public` boolean. **Kein `slug`** (bewusst nicht editierbar).

- [ ] **Step 4:** `GroupController@update(UpdateGroupRequest $request, Group $group)`:
```php
$group->update(['name' => $request->validated('name'), 'public' => $request->boolean('public')]);

return back()->with('success', 'Registry aktualisiert.');
```

- [ ] **Step 5:** Route in der operator-`role:admin,maintainer`-Gruppe — die groups-resource um `update` erweitern ODER separat:
```php
Route::put('groups/{group}', [Admin\GroupController::class, 'update'])->name('groups.update');
```
(Achte darauf, dass `groups/{group}` GET [show] und PUT [update] beide existieren — kein Konflikt, unterschiedliche Verben.)

- [ ] **Step 6: Vue** `admin/groups/Show.vue` — im Kopfbereich ein kleines „Bearbeiten"-Formular (Name-Input + public-Checkbox) via `useForm`, das auf `route('admin.groups.update', group.id)` PUT-tet; der Slug wird nur angezeigt (read-only, mit Hinweis „Slug ist der feste Registry-Endpunkt und nicht änderbar").

- [ ] **Step 7:** Tests grün (auch bestehende Group-Tests); Build; Lint; Pint + PHPStan.
- [ ] **Step 8:** Commit `feat: edit registry name and visibility (slug stays immutable)`.

---

### Task SC3: E2E + volle Suite

- [ ] **Step 1:** `tests/Feature/Admin/GlobalSearchEditEndToEndTest.php` — globale Suche liefert eine Registry; deren Umbenennung wird persistiert und der Slug bleibt; eine erneute Suche findet sie unter dem neuen Namen.
- [ ] **Step 2:** Volle Suite `ddev exec vendor/bin/pest` → alle grün (Gesamtzahl melden). `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run build`, `ddev exec npm run lint:check`.
- [ ] **Step 3:** Commit `test: end-to-end global search and registry edit`.

---

## Self-Review

- **Deckt die Follow-ups:** globale Suche jetzt über Pakete + Registries + Kunden; Registry nachträglich editierbar (Name/Sichtbarkeit).
- **Sicherheit:** Such- und Update-Endpunkte operator-gated; `ilike` mit escaptem Wildcard; Slug bewusst unveränderlich (stabiler Endpunkt, keine Client-Config-Brüche); kein Secret in den Such-Props.
- **Verschoben/Follow-up:** Einladungs-Mail statt Klartext-Passwort bei User-Anlage (braucht Mail-/Set-Passwort-Flow); Bearbeiten weiterer Registry-Aspekte (Domains/Upstreams haben eigene Admin-Seiten); OCI (Phase 2).

# Kontorfix v2.2 – Globale Suche + Filter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Paketliste bekommt serverseitige Filter (Name-Suche, Typ, Sync-Status, Registry), und der Admin-Bereich eine globale Command-Palette (Cmd/Ctrl+K), die Pakete durchsucht und direkt zur Detailseite springt.

**Architecture:** `PackageController@index` filtert die paginierte Query über Query-Parameter (`q`, `type`, `status`, `group`) und liefert die aktuellen Filterwerte zurück; die Vue-Liste steuert das per `router.get(..., { preserveState })`. Die globale Suche nutzt den vorhandenen `PackageSearchController` (ilike-Namenssuche, liefert id/name/type); eine `CommandPalette.vue` (global im Admin-Layout, per Cmd/Ctrl+K) ruft ihn auf und navigiert zu `admin.packages.show`.

**Tech Stack:** Laravel 12, Inertia v2 + Vue 3, Pest, Pint, Larastan L6.

---

## File Structure

- Modify `app/Http/Controllers/Admin/PackageController.php` — `index` mit Filtern.
- Modify `resources/js/pages/admin/packages/Index.vue` — Filterleiste.
- Create `resources/js/components/kontorfix/CommandPalette.vue`; modify das Admin-Layout (`resources/js/layouts/app/AppSidebarLayout.vue` oder `AppLayout.vue`) — Palette global einhängen.
- Tests: `tests/Feature/Admin/PackageFilterTest.php`, ggf. Ergänzung in `PackageSearchTest.php`.

---

### Task GS1: Serverseitige Filter für die Paketliste

**Files:** `app/Http/Controllers/Admin/PackageController.php`, `resources/js/pages/admin/packages/Index.vue`, Test `tests/Feature/Admin/PackageFilterTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use App\Enums\SyncStatus;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

it('filters packages by name, type, status and group', function () {
    $g = Group::factory()->for(Organization::factory())->create();
    $a = Package::factory()->create(['name' => 'acme/alpha', 'type' => 'composer', 'sync_status' => SyncStatus::Synced]);
    $b = Package::factory()->create(['name' => 'beta/widget', 'type' => 'npm', 'sync_status' => SyncStatus::Failed]);
    $g->packages()->attach($a);

    // Namenssuche
    $this->actingAs($this->admin)->get('/admin/packages?q=acme')
        ->assertInertia(fn ($p) => $p->has('packages.data', 1)->where('packages.data.0.name', 'acme/alpha'));

    // Typ
    $this->actingAs($this->admin)->get('/admin/packages?type=npm')
        ->assertInertia(fn ($p) => $p->has('packages.data', 1)->where('packages.data.0.name', 'beta/widget'));

    // Status
    $this->actingAs($this->admin)->get('/admin/packages?status=failed')
        ->assertInertia(fn ($p) => $p->has('packages.data', 1)->where('packages.data.0.name', 'beta/widget'));

    // Gruppe
    $this->actingAs($this->admin)->get("/admin/packages?group={$g->id}")
        ->assertInertia(fn ($p) => $p->has('packages.data', 1)->where('packages.data.0.name', 'acme/alpha'));

    // aktuelle Filterwerte werden zurückgegeben
    $this->actingAs($this->admin)->get('/admin/packages?q=acme&type=composer')
        ->assertInertia(fn ($p) => $p->where('filters.q', 'acme')->where('filters.type', 'composer'));
});
```
Hinweis: die bestehende `index` paginiert (`paginate(25)`) → die Inertia-Struktur ist `packages.data`. Prüfe die genaue bestehende Prop-Form und passe die Pfade im Test nur an, falls sie abweicht (die FILTER-Assertions müssen erhalten bleiben).

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3:** `PackageController@index` erweitern (Postgres → `ilike`):
```php
public function index(Request $request): Response
{
    $q = trim((string) $request->query('q', ''));
    $type = $request->query('type');
    $status = $request->query('status');
    $group = $request->query('group');

    $packages = Package::query()
        ->withCount('groups')
        ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
        ->when(in_array($type, ['composer', 'npm'], true), fn ($query) => $query->where('type', $type))
        ->when(in_array($status, ['pending', 'syncing', 'synced', 'failed'], true), fn ($query) => $query->where('sync_status', $status))
        ->when(is_string($group) && $group !== '', fn ($query) => $query->whereHas('groups', fn ($g) => $g->whereKey($group)))
        ->latest()
        ->paginate(25)
        ->withQueryString()
        ->through(fn (Package $p) => [
            'id' => $p->id, 'type' => $p->type, 'name' => $p->name,
            'sync_status' => $p->sync_status, 'sync_error' => $p->sync_error,
            'groups_count' => $p->groups_count, 'synced_at' => $p->synced_at?->diffForHumans(),
        ]);

    return Inertia::render('admin/packages/Index', [
        'packages' => $packages,
        'groups' => Group::orderBy('name')->get(['id', 'name', 'slug']),
        'filters' => ['q' => $q, 'type' => $type, 'status' => $status, 'group' => $group],
    ]);
}
```

- [ ] **Step 4: Vue** `admin/packages/Index.vue` — eine Filterleiste über der Tabelle: Text-Input (`q`, debounced), Selects für Typ (alle/composer/npm), Status (alle/pending/syncing/synced/failed), Registry (alle/…aus `groups`). Änderungen lösen `router.get(route('admin.packages.index'), { q, type, status, group }, { preserveState: true, preserveScroll: true, replace: true })` aus. Initialwerte aus dem neuen `filters`-Prop. Nutze native `<select>` (Hausmuster) + das vorhandene `Input`. Ein „Zurücksetzen"-Link, wenn Filter aktiv sind.

- [ ] **Step 5:** Tests grün (auch bestehende Package-Tests); Build; Pint + PHPStan.
- [ ] **Step 6:** Commit `feat: server-side filters for the package list`.

---

### Task GS2: Globale Command-Palette (Cmd/Ctrl+K)

Nutzt den vorhandenen `PackageSearchController` (Route `admin.package-search`, liefert `[{id,name,type}]`).

**Files:** `resources/js/components/kontorfix/CommandPalette.vue`, ein Admin-Layout (`resources/js/layouts/app/AppSidebarLayout.vue` — finde das tatsächlich genutzte Layout via `grep -rl "AppSidebar" resources/js/layouts`), ggf. `tests/Feature/Admin/PackageSearchTest.php` (nur falls eine Assertion fehlt).

- [ ] **Step 1:** Sieh dir `app/Http/Controllers/Admin/PackageSearchController.php` und die bestehende Nutzung in `resources/js/components/kontorfix/PackagePicker.vue` an (dort wird `route('admin.package-search')` per fetch/axios mit `q` aufgerufen). Übernimm das exakte Fetch-/CSRF-Muster.

- [ ] **Step 2: `CommandPalette.vue`**
- Ein global gemountetes Overlay-Dialog, das auf `keydown` mit `(e.metaKey || e.ctrlKey) && e.key === 'k'` öffnet (`e.preventDefault()`), `Escape` schließt.
- Ein Suchfeld (autofocus). Bei Eingabe (debounced ~200ms) `route('admin.package-search')?q=…` abrufen; Ergebnisse (Name mono + Typ-Badge) listen.
- Pfeil-hoch/-runter navigiert, Enter öffnet das markierte Paket via `router.visit(route('admin.packages.show', item.id))` und schließt die Palette.
- Leerer/kein Treffer → dezenter Hinweis.
- Nur im Admin-Bereich sinnvoll → im Admin-Layout einhängen (nicht im Portal/Auth). Ein dezenter Hinweis „⌘K" ist optional.

- [ ] **Step 3:** Palette im tatsächlich genutzten Admin-Layout global einbinden (`<CommandPalette />` neben dem `<slot />`), sodass sie auf allen Admin-Seiten verfügbar ist.

- [ ] **Step 4:** Falls der Such-Endpoint noch keinen Test hat, der die Namensfilterung belegt, ergänze in `PackageSearchTest.php` einen Fall `?q=` filtert korrekt (operator-gated Admin). (Der Endpoint liegt in der operator-`role:admin,maintainer`-Gruppe → handelnder Admin via `->operator()`.)

- [ ] **Step 5:** `ddev exec npm run build` (ohne Fehler) + `ddev exec npm run lint:check` (sauber). Bestehende Tests grün. Pint/PHPStan (falls PHP geändert).
- [ ] **Step 6:** Commit `feat: global command palette (cmd+k) package search`.

---

### Task GS3: E2E + volle Suite

- [ ] **Step 1:** `tests/Feature/Admin/PackageFilterEndToEndTest.php` — kombinierte Filter (`?q=…&type=…&status=…`) liefern die erwartete Teilmenge; leere Filter liefern alles; der Such-Endpoint liefert Treffer für einen Teilnamen. (Rein serverseitig testbar; die Palette selbst ist Frontend.)
- [ ] **Step 2:** Volle Suite `ddev exec vendor/bin/pest` → alle grün (Gesamtzahl melden). `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run build`, `ddev exec npm run lint:check`.
- [ ] **Step 3:** Commit `test: end-to-end package filters and search`.

---

## Self-Review

- **Deckt den Wunsch:** Listen-Filter (Name/Typ/Status/Registry) + globale Command-Palette (Cmd/Ctrl+K) über Pakete → Detailseite.
- **Sicherheit:** alles in der operator-`role:admin,maintainer`-Gruppe (bereits operator-gated); `ilike`-Suche mit escaptem Wildcard (`addcslashes`), keine SQL-Injection; Filterwerte gegen Whitelist geprüft (type/status), `group` als whereKey.
- **Verschoben/Follow-up:** Portal-Paketlisten-Filter (analog, für Kunden) als nächste kleine Erweiterung; globale Suche über Registries/Kunden zusätzlich zu Paketen; TODO(multi-tenant) im PackageSearchController bleibt (Betreiber-Modell). Danach OCI (Phase 2).

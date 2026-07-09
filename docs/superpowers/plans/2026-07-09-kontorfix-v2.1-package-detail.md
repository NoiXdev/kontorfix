# Kontorfix v2.1 – Paket-Detailseiten Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pro Paket eine Infoseite mit allen Details — Metadaten, alle Versionen mit Abhängigkeiten, Registry-Zuordnung, Sync-Status/-Fehler und Install-Snippets — im Admin und (read-only) im Kunden-Portal.

**Architecture:** Die Daten liegen bereits: `Package` (type/name/description/sync_status/sync_error/synced_at/repository_url/dist_tags) mit `versions()` (jede Version hat `metadata` als Array = composer.json/package.json) und `groups()`. Ein `PackageDependencies`-Helper extrahiert aus den Versions-Metadaten die Abhängigkeiten typ-abhängig (composer: `require`/`require-dev`; npm: `dependencies`/`devDependencies`). Ein Admin-`show` (operator-gated) und ein Portal-`show` (ACL-gescoped über die vorhandene `ResolvesRegistryPackage`/Portal-Logik) rendern die Detailseite.

**Tech Stack:** Laravel 12, Inertia v2 + Vue 3, Pest, Pint, Larastan L6.

---

## File Structure

- Create `app/Services/Package/PackageDependencies.php` — Abhängigkeiten aus Versions-Metadaten.
- Modify `app/Http/Controllers/Admin/PackageController.php` — `show`.
- Modify `routes/web.php` — `packages.show` in der `role:admin,maintainer`-Operator-Gruppe.
- Create `resources/js/pages/admin/packages/Show.vue`; modify `resources/js/pages/admin/packages/Index.vue` (Namen verlinken).
- Portal: modify `app/Http/Controllers/Portal/RegistryController.php` (oder neuer `PortalPackageController`) — `showPackage`; route `portal.packages.show`; `resources/js/pages/portal/Package.vue`; Paketliste in `portal/Registry.vue` verlinken.
- Tests unter `tests/Unit/`, `tests/Feature/Admin/`, `tests/Feature/Portal/`.

---

### Task PD1: PackageDependencies-Helper

**Files:** `app/Services/Package/PackageDependencies.php`, Test `tests/Unit/PackageDependenciesTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\PackageType;
use App\Services\Package\PackageDependencies;

it('extracts composer require and require-dev', function () {
    $meta = ['require' => ['php' => '^8.2', 'monolog/monolog' => '^3.0'], 'require-dev' => ['pestphp/pest' => '^2.0']];
    $deps = app(PackageDependencies::class)->for(PackageType::Composer, $meta);

    expect($deps['runtime'])->toBe(['php' => '^8.2', 'monolog/monolog' => '^3.0']);
    expect($deps['dev'])->toBe(['pestphp/pest' => '^2.0']);
});

it('extracts npm dependencies and devDependencies', function () {
    $meta = ['dependencies' => ['left-pad' => '^1.0.0'], 'devDependencies' => ['jest' => '^29']];
    $deps = app(PackageDependencies::class)->for(PackageType::Npm, $meta);

    expect($deps['runtime'])->toBe(['left-pad' => '^1.0.0']);
    expect($deps['dev'])->toBe(['jest' => '^29']);
});

it('returns empty arrays for missing keys', function () {
    $deps = app(PackageDependencies::class)->for(PackageType::Composer, []);
    expect($deps)->toBe(['runtime' => [], 'dev' => []]);
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: Implement**
```php
<?php

namespace App\Services\Package;

use App\Enums\PackageType;

class PackageDependencies
{
    /**
     * @param  array<string,mixed>  $metadata
     * @return array{runtime: array<string,string>, dev: array<string,string>}
     */
    public function for(PackageType $type, array $metadata): array
    {
        [$runtimeKey, $devKey] = $type === PackageType::Npm
            ? ['dependencies', 'devDependencies']
            : ['require', 'require-dev'];

        return [
            'runtime' => $this->stringMap($metadata[$runtimeKey] ?? null),
            'dev' => $this->stringMap($metadata[$devKey] ?? null),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $name => $constraint) {
            if (is_string($name) && is_string($constraint)) {
                $out[$name] = $constraint;
            }
        }

        return $out;
    }
}
```

- [ ] **Step 4:** Run → PASS; Pint + PHPStan.
- [ ] **Step 5:** Commit `feat: package dependency extraction from version metadata`.

---

### Task PD2: Admin-Paket-Detailseite

**Files:** `app/Http/Controllers/Admin/PackageController.php`, `routes/web.php`, `resources/js/pages/admin/packages/Show.vue`, `resources/js/pages/admin/packages/Index.vue`, Test `tests/Feature/Admin/PackageDetailTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

it('shows a package with versions, dependencies and groups', function () {
    $pkg = Package::factory()->create(['type' => 'composer', 'name' => 'acme/widget']);
    PackageVersion::factory()->for($pkg, 'package')->create([
        'version' => '1.0.0.0', 'version_pretty' => 'v1.0.0',
        'metadata' => ['require' => ['php' => '^8.2', 'monolog/monolog' => '^3.0'], 'description' => 'x'],
    ]);
    $group = Group::factory()->for(Organization::factory())->create(['name' => 'Kadenz']);
    $group->packages()->attach($pkg);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/packages/Show')
            ->where('package.name', 'acme/widget')
            ->has('versions', 1)
            ->where('versions.0.version', 'v1.0.0')
            ->where('versions.0.dependencies.runtime', ['php' => '^8.2', 'monolog/monolog' => '^3.0'])
            ->has('groups', 1)
            ->where('groups.0.name', 'Kadenz'));
});

it('is operator-gated', function () {
    $pkg = Package::factory()->create();
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    $this->actingAs($custAdmin)->get("/admin/packages/{$pkg->id}")->assertForbidden();
});
```
Prüfe die PackageVersion-Factory: falls `->for($pkg, 'package')` nicht greift, nutze `PackageVersion::factory()->create(['package_id' => $pkg->id, ...])`. Falls `version` andere Pflichtfelder braucht (source_reference nullable?), ergänze minimal.

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3:** `PackageController@show(Package $package, PackageDependencies $deps)`:
```php
$package->load(['versions', 'groups:id,name,slug']);

return Inertia::render('admin/packages/Show', [
    'package' => [
        'id' => $package->id,
        'type' => $package->type->value,
        'name' => $package->name,
        'description' => $package->description,
        'repository_url' => $package->repository_url,
        'sync_status' => $package->sync_status->value,
        'sync_error' => $package->sync_error,
        'synced_at' => $package->synced_at?->diffForHumans(),
    ],
    'versions' => $package->versions->map(fn (\App\Models\PackageVersion $v) => [
        'version' => $v->version_pretty ?? $v->version,
        'released_at' => $v->released_at?->toDateString(),
        'reference' => $v->source_reference,
        'dependencies' => $deps->for($package->type, $v->metadata ?? []),
    ]),
    'groups' => $package->groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'slug' => $g->slug]),
]);
```

- [ ] **Step 4:** Route in der `role:admin,maintainer`-Operator-Gruppe:
```php
Route::get('packages/{package}', [Admin\PackageController::class, 'show'])->name('packages.show');
```
(Achtung: NICHT mit der `resource(...)->only(['index','store','destroy'])` kollidieren — separat als GET ergänzen.)

- [ ] **Step 5: Vue** `admin/packages/Show.vue`: Kopf (Name mono, Typ-Badge, `StatusPill` für sync_status, Beschreibung, Repo-Link, sync_error falls vorhanden); Abschnitt „Registries" (Liste der Gruppen, verlinkt); Abschnitt „Versionen" (pro Version: Version, Datum, Referenz; ausklappbare Abhängigkeiten runtime/dev als Name→Constraint-Liste, JetBrains-Mono); Abschnitt „Installation" (generisch: `composer require {name}` bzw. `npm install {name}`). In `admin/packages/Index.vue` den Paketnamen als `Link` auf `route('admin.packages.show', pkg.id)` rendern.

- [ ] **Step 6:** Tests grün (auch bestehende Package-Tests); Build; Pint + PHPStan.
- [ ] **Step 7:** Commit `feat: admin package detail page with versions, dependencies and groups`.

---

### Task PD3: Portal-Paket-Detailseite (read-only)

Der Kunde sieht pro Paket seiner Registry eine Detailseite mit Versionen/Abhängigkeiten und einem Install-Snippet für **seine** Registry-URL.

**Files:** `app/Http/Controllers/Portal/RegistryController.php` (Methode `showPackage`) oder neuer Controller, `routes/web.php` (portal-Gruppe), `resources/js/pages/portal/Package.vue`, `resources/js/pages/portal/Registry.vue` (Paketliste verlinken), Test `tests/Feature/Portal/PortalPackageDetailTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
    $this->org = Organization::factory()->create();
    $this->member = User::factory()->for($this->org)->create(['role' => UserRole::Member]);
    $this->group = Group::factory()->for($this->org)->create(['slug' => 'acme']);
    $this->pkg = Package::factory()->create(['type' => 'composer', 'name' => 'acme/widget']);
    PackageVersion::factory()->create(['package_id' => $this->pkg->id, 'version' => '1.0.0.0', 'version_pretty' => 'v1.0.0', 'metadata' => ['require' => ['php' => '^8.2']]]);
    $this->group->packages()->attach($this->pkg);
});

it('shows a package detail within the customers own registry', function () {
    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}/packages/{$this->pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Package')
            ->where('package.name', 'acme/widget')->has('versions', 1)
            ->where('install', fn ($v) => str_contains($v, 'acme/widget')));
});

it('forbids a package not in the members registry', function () {
    $otherGroup = Group::factory()->for(Organization::factory()->create())->create();
    $otherPkg = Package::factory()->create();
    $otherGroup->packages()->attach($otherPkg);

    // fremde Registry
    $this->actingAs($this->member)->get("/portal/registries/{$otherGroup->id}/packages/{$otherPkg->id}")->assertForbidden();
    // eigenes Registry, aber Paket nicht zugewiesen
    $unassigned = Package::factory()->create();
    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}/packages/{$unassigned->id}")->assertNotFound();
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3:** `showPackage(Request, Group $group, Package $package)`:
- `$this->authorize('view', $group)` (GroupPolicy — eigene Org; nutzt bereits vorhandenes Muster in Portal-RegistryController).
- `abort_unless($group->packages()->whereKey($package->id)->exists(), 404)` — Paket muss dieser Registry zugewiesen sein.
- Props analog zur Admin-Show, plus `install` = Install-Snippet mit der Registry-Basis-URL (nutze den vorhandenen `RegistryUrl`-Service: composer `composer require {name}` mit Repo-URL bzw. npm — halte es simpel, ein sinnvoller Copy-Text). Nutze den `PackageDependencies`-Service für die Abhängigkeiten.

- [ ] **Step 4:** Route in der `portal`-Gruppe:
```php
Route::get('registries/{group}/packages/{package}', [RegistryController::class, 'showPackage'])->name('registries.package');
```

- [ ] **Step 5: Vue** `portal/Package.vue` (read-only Variante der Admin-Show: Kopf, Versionen+Abhängigkeiten, Install-Snippet). In `portal/Registry.vue` die Paketnamen auf `route('portal.registries.package', [registry.id, pkg.id])` verlinken.

- [ ] **Step 6:** Tests grün; Build; Pint + PHPStan.
- [ ] **Step 7:** Commit `feat: read-only package detail in the customer portal`.

---

### Task PD4: E2E + volle Suite

- [ ] **Step 1:** `tests/Feature/PackageDetailEndToEndTest.php` — Admin sieht Paket-Detail mit Abhängigkeiten; Member sieht dasselbe Paket read-only in seiner Registry mit Install-Snippet; Cross-Registry bleibt dicht.
- [ ] **Step 2:** Volle Suite `ddev exec vendor/bin/pest` → alle grün (Gesamtzahl melden). `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run build`.
- [ ] **Step 3:** Commit `test: end-to-end package detail (admin and portal)`.

---

## Self-Review

- **Deckt den Wunsch:** Detailseite pro Paket mit Versionen, **Abhängigkeiten**, Registry-Zuordnung, Sync-Status/-Fehler, Install-Snippets — Admin (PD2) und read-only Portal (PD3).
- **Sicherheit:** Admin-Show operator-gated (via Route-Gruppe); Portal-Show über `GroupPolicy@view` (eigene Org) + Paket-muss-in-Registry-Prüfung (404 sonst) — keine Cross-Tenant-Leaks; kein Token/Secret in Props.
- **Verschoben/Follow-up:** Download-Statistiken (kein Tracking vorhanden); Readme-Rendering (Package hat kein readme-Feld — bleibt Metadaten-`description`); v2.2 globale Suche + Filter als nächste Phase.

# Kontorfix v2.0 – Kunden-/Vendor-Verwaltung Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Betreiber legen Kunden (Organisationen) und deren Nutzer im GUI an, weisen Registries (Gruppen) einer Kunden-Org zu und sehen pro Kunde eine „Vendor"-Übersicht — der komplette Onboarding-Flow fürs Kundenportal.

**Architecture:** Das Datenmodell trägt das bereits (Organization ↔ User ↔ Group/Registry ↔ RegistryToken/Domain). Diese Phase ergänzt die fehlende Verwaltungsoberfläche: Organizations-CRUD, Users-CRUD und die org-Auswahl beim Gruppen-Anlegen (aktuell hart auf die Betreiber-Org verdrahtet). Alles streng unter `role:admin` (nur Betreiber-Admins; Nutzer-/Rollen-Anlage ist privilegiert). Schutzregeln: die Operator-Org und der letzte Operator-Admin sind unlöschbar, niemand löscht sich selbst.

**Tech Stack:** Laravel 12, Inertia v2 + Vue 3 + shadcn-vue, Pest, Pint, Larastan L6.

---

## File Structure

- Modify `app/Models/Organization.php` — Relationen `groups()`, `registryTokens()`.
- Create `app/Http/Controllers/Admin/OrganizationController.php` (index/show/store/destroy) + `StoreOrganizationRequest`.
- Create `app/Http/Controllers/Admin/UserController.php` (index/store/update/destroy) + `StoreUserRequest` + `UpdateUserRequest`.
- Modify `app/Http/Controllers/Admin/GroupController.php` + `StoreGroupRequest` — organization_id wählbar.
- Modify `routes/web.php` — organizations/users-Ressourcen in der `role:admin`-Gruppe.
- Create `resources/js/pages/admin/organizations/{Index,Show}.vue`, `resources/js/pages/admin/users/Index.vue`; modify `resources/js/pages/admin/groups/Index.vue` (Org-Auswahl) und `resources/js/components/AppSidebar.vue` (Nav „Kunden"/„Nutzer", admin-only).
- Tests unter `tests/Feature/Admin/`.

---

### Task V1: Organizations-CRUD + Vendor-Detailseite

**Files:** `app/Models/Organization.php`, `app/Http/Controllers/Admin/OrganizationController.php`, `app/Http/Requests/Admin/StoreOrganizationRequest.php`, `routes/web.php`, `resources/js/pages/admin/organizations/Index.vue`, `resources/js/pages/admin/organizations/Show.vue`, Test `tests/Feature/Admin/OrganizationAdminTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
});

it('lists organizations with counts', function () {
    $cust = Organization::factory()->create(['name' => 'Kadenz GmbH']);
    User::factory()->for($cust)->create(['role' => UserRole::Member]);
    Group::factory()->for($cust)->create();

    $this->actingAs($this->admin)->get('/admin/organizations')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/organizations/Index')
            ->has('organizations', 2)
            ->where('organizations', fn ($orgs) => collect($orgs)->firstWhere('name', 'Kadenz GmbH')['users_count'] === 1));
});

it('forbids maintainers', function () {
    $m = User::factory()->for(Organization::factory())->create(['role' => UserRole::Maintainer]);
    $this->actingAs($m)->get('/admin/organizations')->assertForbidden();
});

it('creates a customer organization (never an operator org from the gui)', function () {
    $this->actingAs($this->admin)->post('/admin/organizations', ['name' => 'Neu GmbH', 'slug' => 'neu'])
        ->assertRedirect();
    $org = Organization::where('slug', 'neu')->first();
    expect($org)->not->toBeNull()->and($org->is_operator)->toBeFalse();
});

it('shows a vendor detail page with registries, users and tokens', function () {
    $cust = Organization::factory()->create(['name' => 'Kadenz GmbH']);
    Group::factory()->for($cust)->create(['name' => 'Kadenz Registry']);
    User::factory()->for($cust)->create();

    $this->actingAs($this->admin)->get("/admin/organizations/{$cust->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/organizations/Show')
            ->where('organization.name', 'Kadenz GmbH')->has('registries', 1)->has('users', 1)->has('tokens'));
});

it('refuses to delete the operator org, deletes an empty customer org', function () {
    $this->actingAs($this->admin)->delete("/admin/organizations/{$this->operator->id}")->assertSessionHasErrors();
    expect(Organization::find($this->operator->id))->not->toBeNull();

    $empty = Organization::factory()->create();
    $this->actingAs($this->admin)->delete("/admin/organizations/{$empty->id}")->assertRedirect();
    expect(Organization::find($empty->id))->toBeNull();
});

it('refuses to delete a customer org that still has users or registries', function () {
    $cust = Organization::factory()->create();
    Group::factory()->for($cust)->create();
    $this->actingAs($this->admin)->delete("/admin/organizations/{$cust->id}")->assertSessionHasErrors();
    expect(Organization::find($cust->id))->not->toBeNull();
});
```
Prüfe die Group-Factory: `Group::factory()->for($org)` muss greifen (Relation `organization()` existiert). Falls nicht `->for()`, nutze `->create(['organization_id' => $org->id])`.

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: Organization-Relationen** ergänzen:
```php
/** @return HasMany<Group, $this> */
public function groups(): HasMany
{
    return $this->hasMany(Group::class);
}

/** @return HasMany<RegistryToken, $this> */
public function registryTokens(): HasMany
{
    return $this->hasMany(RegistryToken::class);
}
```
(Imports für `Group`, `RegistryToken` ergänzen.)

- [ ] **Step 4: `StoreOrganizationRequest`** — `authorize(): true`; Regeln: `name` required string max:190; `slug` required kebab (`regex:/^[a-z0-9-]+$/`) unique:organizations,slug. `is_operator` wird NIE aus dem Request übernommen.

- [ ] **Step 5: `OrganizationController`**
- `index`: rendert `admin/organizations/Index` mit `organizations` = alle Orgs mit `users_count`, `groups_count`, Feldern id/name/slug/is_operator (nutze `withCount(['users','groups'])`).
- `show(Organization $organization)`: rendert `admin/organizations/Show` mit `organization` (id/name/slug/is_operator), `registries` (Gruppen der Org: id/name/slug + domains + packages_count), `users` (id/name/email/role), `tokens` (id/name/ability/group-name; NIE der Token-Hash).
- `store(StoreOrganizationRequest)`: `Organization::create([...validated, 'is_operator' => false])`; `back()->with('success', ...)`.
- `destroy(Organization $organization)`: throw ValidationException, wenn `is_operator` ODER `users()->exists()` ODER `groups()->exists()` (klare Meldung); sonst löschen.

- [ ] **Step 6: Routen** in der `role:admin`-Gruppe (neben oidc/storage):
```php
Route::resource('organizations', Admin\OrganizationController::class)->only(['index', 'show', 'store', 'destroy'])->parameters(['organizations' => 'organization']);
```

- [ ] **Step 7: Vue** `admin/organizations/Index.vue` (Liste: Name, Slug, Kunde/Betreiber-Badge, Anzahl Registries/Nutzer, Link auf Detail, Anlege-Formular, Löschen) und `admin/organizations/Show.vue` (Kopf mit Name/Slug; drei Blöcke Registries / Nutzer / Tokens; Registries verlinken auf die Gruppen-Verwaltung). Muster: `admin/upstreams/Index.vue`. Sidebar-Nav-Eintrag „Kunden" (admin-only, Icon z.B. `Building2`) in `AppSidebar.vue` ergänzen.

- [ ] **Step 8:** `ddev exec vendor/bin/pest tests/Feature/Admin/OrganizationAdminTest.php` grün; `ddev exec npm run build`; Pint + PHPStan.
- [ ] **Step 9:** Commit `feat: customer organization management and vendor detail page`.

---

### Task V2: Users-CRUD

**Files:** `app/Http/Controllers/Admin/UserController.php`, `app/Http/Requests/Admin/StoreUserRequest.php`, `app/Http/Requests/Admin/UpdateUserRequest.php`, `routes/web.php`, `resources/js/pages/admin/users/Index.vue`, `resources/js/components/AppSidebar.vue`, Test `tests/Feature/Admin/UserAdminTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
});

it('creates a user with an org, role and hashed, verified credentials', function () {
    $cust = Organization::factory()->create();

    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'Neu', 'email' => 'neu@kunde.test', 'organization_id' => $cust->id,
        'role' => 'member', 'password' => 'geheim-1234',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $u = User::where('email', 'neu@kunde.test')->first();
    expect($u)->not->toBeNull()
        ->and($u->organization_id)->toBe($cust->id)
        ->and($u->role)->toBe(UserRole::Member)
        ->and($u->email_verified_at)->not->toBeNull()
        ->and(\Illuminate\Support\Facades\Hash::check('geheim-1234', $u->password))->toBeTrue();
});

it('forbids maintainers from managing users', function () {
    $m = User::factory()->for(Organization::factory())->create(['role' => UserRole::Maintainer]);
    $this->actingAs($m)->get('/admin/users')->assertForbidden();
    $this->actingAs($m)->post('/admin/users', ['name' => 'x', 'email' => 'x@x.test', 'organization_id' => $this->operator->id, 'role' => 'admin', 'password' => 'geheim-1234'])->assertForbidden();
});

it('can change a users role', function () {
    $u = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);
    $this->actingAs($this->admin)->put("/admin/users/{$u->id}", ['role' => 'maintainer'])->assertRedirect();
    expect($u->fresh()->role)->toBe(UserRole::Maintainer);
});

it('refuses to delete yourself or the last operator admin', function () {
    // sich selbst
    $this->actingAs($this->admin)->delete("/admin/users/{$this->admin->id}")->assertSessionHasErrors();
    expect(User::find($this->admin->id))->not->toBeNull();

    // letzter Operator-Admin (admin ist der einzige) — ein weiterer Nutzer, aber kein zweiter Operator-Admin
    $member = User::factory()->for($this->operator)->create(['role' => UserRole::Member]);
    $this->actingAs($member); // egal, wir prüfen das Ziel
    $this->actingAs($this->admin)->delete("/admin/users/{$this->admin->id}")->assertSessionHasErrors();
});

it('deletes a regular user', function () {
    $u = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);
    $this->actingAs($this->admin)->delete("/admin/users/{$u->id}")->assertRedirect();
    expect(User::find($u->id))->toBeNull();
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: `StoreUserRequest`** — Regeln: `name` required; `email` required email unique:users,email; `organization_id` required uuid exists:organizations,id; `role` required `Rule::enum(UserRole::class)`; `password` required string min:8.
  `UpdateUserRequest` — `role` required `Rule::enum(UserRole::class)`; optional `name`/`email` (email unique ignore self). Halte es minimal: für Update reicht `role` (+ optional name).

- [ ] **Step 4: `UserController`**
- `index`: rendert `admin/users/Index` mit `users` (id/name/email/role, organization-name, letzte Anmeldung optional) und `organizations` (id/name für das Anlege-Dropdown).
- `store(StoreUserRequest)`: `User::create([...])` (password wird durch den `hashed`-Cast gehasht); danach `->forceFill(['email_verified_at' => now()])->save()` (Betreiber-angelegte Konten sind vertrauenswürdig); `back()->with('success', ...)`.
- `update(UpdateUserRequest, User $user)`: `role` (und ggf. name) setzen; speichern.
- `destroy(User $user)`: throw ValidationException, wenn `$user->is($request->user())` (sich selbst) ODER wenn `$user` der **letzte** Admin einer Operator-Org ist (`$user->role === Admin && $user->organization->is_operator && User::where('organization_id',$user->organization_id)->where('role','admin')->count() <= 1`). Sonst löschen.

- [ ] **Step 5: Routen** in der `role:admin`-Gruppe:
```php
Route::resource('users', Admin\UserController::class)->only(['index', 'store', 'update', 'destroy']);
```

- [ ] **Step 6: Vue** `admin/users/Index.vue` (Tabelle: Name, E-Mail, Org, Rolle; Anlege-Formular mit Org-Dropdown + Rollen-Select + Passwort; Rolle inline änderbar; Löschen). Sidebar-Nav „Nutzer" (admin-only, Icon `Users`).

- [ ] **Step 7:** Tests grün; Build; Pint + PHPStan.
- [ ] **Step 8:** Commit `feat: user management with org and role assignment`.

---

### Task V3: Registry (Gruppe) einer Kunden-Org zuweisen

Behebt die Kern-Lücke: `GroupController@store` hängt jede Gruppe hart an die Betreiber-Org. Neu: der Admin wählt die Ziel-Org.

**Files:** `app/Http/Controllers/Admin/GroupController.php`, `app/Http/Requests/Admin/StoreGroupRequest.php`, `resources/js/pages/admin/groups/Index.vue`, Test-Ergänzung `tests/Feature/Admin/GroupOrgAssignmentTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

it('creates a registry under the chosen customer organization', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($operator)->create(['role' => UserRole::Admin]);
    $cust = Organization::factory()->create();

    $this->actingAs($admin)->post('/admin/groups', [
        'name' => 'Kadenz Registry', 'slug' => 'kadenz', 'organization_id' => $cust->id,
    ])->assertRedirect();

    expect(Group::where('slug', 'kadenz')->first()->organization_id)->toBe($cust->id);
});

it('defaults to the operator org when none is given', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($operator)->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->post('/admin/groups', ['name' => 'Intern', 'slug' => 'intern'])->assertRedirect();
    expect(Group::where('slug', 'intern')->first()->organization_id)->toBe($operator->id);
});
```

- [ ] **Step 2:** Run → FAIL (organization_id wird ignoriert).

- [ ] **Step 3:** `StoreGroupRequest` um `'organization_id' => ['nullable','uuid','exists:organizations,id']` ergänzen.

- [ ] **Step 4:** `GroupController@store` — `organization_id` aus dem Request nehmen, Fallback auf die eigene Org:
```php
'organization_id' => $request->validated('organization_id') ?? $request->user()->organization_id,
```
`index` zusätzlich `organizations` (id/name) an die Props geben (fürs Dropdown) und pro Gruppe die Org anzeigen (`with('organization:id,name')`).

- [ ] **Step 5: Vue** `admin/groups/Index.vue` — im Anlege-Formular ein Org-Dropdown (Default Betreiber-Org); in der Liste eine Spalte „Kunde/Org".

- [ ] **Step 6:** Tests grün (auch bestehende Group-Tests); Build; Pint + PHPStan.
- [ ] **Step 7:** Commit `feat: assign a registry to a customer organization on creation`.

---

### Task V4: E2E-Onboarding + volle Suite + Sidebar

**Files:** `tests/Feature/Admin/VendorOnboardingTest.php`, ggf. `AppSidebar.vue` (falls Nav-Einträge noch fehlen).

- [ ] **Step 1: E2E-Test** — der komplette Onboarding-Flow als Betreiber-Admin: Kunden-Org anlegen → Member-User darin anlegen → Registry (Gruppe) der Org zuweisen → Vendor-Detailseite zeigt Registry + User. Danach: der Member kann sich (via actingAs) im Portal einloggen und sieht genau diese Registry.
```php
<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

it('onboards a customer end-to-end: org -> user -> registry -> portal', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($operator)->create(['role' => UserRole::Admin]);

    // 1) Kunden-Org
    $this->actingAs($admin)->post('/admin/organizations', ['name' => 'Kadenz GmbH', 'slug' => 'kadenz-org'])->assertRedirect();
    $cust = Organization::where('slug', 'kadenz-org')->firstOrFail();

    // 2) Member-User
    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'Kadenz Kunde', 'email' => 'kunde@kadenz.test', 'organization_id' => $cust->id, 'role' => 'member', 'password' => 'geheim-1234',
    ])->assertRedirect();
    $member = User::where('email', 'kunde@kadenz.test')->firstOrFail();

    // 3) Registry der Org zuweisen
    $this->actingAs($admin)->post('/admin/groups', ['name' => 'Kadenz Registry', 'slug' => 'kadenz-reg', 'organization_id' => $cust->id])->assertRedirect();

    // 4) Vendor-Detailseite
    $this->actingAs($admin)->get("/admin/organizations/{$cust->id}")
        ->assertInertia(fn ($p) => $p->has('registries', 1)->has('users', 1));

    // 5) Der Kunde sieht seine Registry im Portal
    $this->actingAs($member)->get('/portal')
        ->assertInertia(fn ($p) => $p->has('registries', 1)->where('registries.0.slug', 'kadenz-reg'));
});
```

- [ ] **Step 2:** Volle Suite `ddev exec vendor/bin/pest` → alle grün (Gesamtzahl melden). `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run build`.
- [ ] **Step 3:** Commit `test: end-to-end customer onboarding flow`.

---

## Self-Review

- **Deckt die Lücke:** Kunden (Orgs) + Nutzer im GUI, Registry→Kunde-Zuweisung, Vendor-Detailseite — der Onboarding-Flow fürs Portal ist damit fahrbar (V1–V4).
- **Sicherheit:** alles unter `role:admin` (Nutzer-/Rollen-Anlage ist privilegiert); Passwörter gehasht (`hashed`-Cast), `email_verified_at` gesetzt; Operator-Org + letzter Operator-Admin + Selbstlöschung geschützt; `is_operator` nie aus dem Request; Token-Hash nie in Props. **Security-Review nach V4 einplanen** (Fokus: Privilege Escalation über User-/Rollen-Anlage, Org-Grenzen, Löschschutz).
- **Verschoben/Follow-up:** Passwort-Reset-/Einladungs-Mail statt Klartext-Passwort durch den Operator; Bearbeiten von Org-Slug/Name (nur Anlegen+Löschen umgesetzt); TODO(multi-tenant)-Marker in Group-Index/Package-Search bleiben (Betreiber-Modell). Die geplanten Folgephasen v2.1 (Paket-Detailseiten) und v2.2 (globale Suche + Filter) bauen darauf auf.

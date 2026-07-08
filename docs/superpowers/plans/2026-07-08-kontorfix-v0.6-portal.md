# Kontorfix v0.6 – Kunden-Portal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kunden (Rolle `member`) bekommen eine gefilterte Read-only-Sicht auf die Registries *ihrer* Organization mit fertigen Copy-Paste-Setup-Snippets (composer/npm) und selbstverwalteten Tokens.

**Architecture:** Gleiche Laravel+Inertia-App, neuer `/portal`-Bereich neben `/admin`. Alle Daten strikt auf `auth()->user()->organization` gescoped — ein Kunde sieht nie fremde Registries, Pakete oder Tokens. Eine `RegistryUrl`-Service kapselt die Basis-URL-Ableitung einer Gruppe (Custom-Domain ODER `app.url`/r/{slug}) außerhalb eines Registry-Requests; ein `SetupSnippetBuilder` erzeugt daraus composer.json/auth.json/.npmrc-Snippets. Policies erzwingen die Org-Grenze zentral.

**Tech Stack:** Laravel 12, Inertia v2 + Vue 3, Tailwind 3, shadcn-vue, Pest, Pint, Larastan L6.

---

## File Structure

- Create `app/Services/Registry/RegistryUrl.php` — leitet Basis-URL + Auth-Host einer Gruppe ab (Domain oder Slug-Pfad).
- Create `app/Services/Registry/SetupSnippetBuilder.php` — baut composer-, auth.json-, npm-Snippets aus einer Gruppe.
- Create `app/Policies/GroupPolicy.php` — `view` nur für eigene Org.
- Create `app/Policies/RegistryTokenPolicy.php` — `delete` nur für Tokens der eigenen Org.
- Create `app/Http/Controllers/Portal/RegistryController.php` — index (eigene Registries), show (Detail + Snippets + Pakete).
- Create `app/Http/Controllers/Portal/TokenController.php` — index/store/destroy, hart auf eigene Org gescoped.
- Create `app/Http/Requests/Portal/StorePortalTokenRequest.php` — Validierung, Gruppe muss der eigenen Org gehören.
- Create `resources/js/pages/portal/Registries.vue` — Registry-Übersicht.
- Create `resources/js/pages/portal/Registry.vue` — Detail: Snippets, Paketliste, Token-Panel.
- Modify `routes/web.php` — `/portal`-Routengruppe; `member` wird von `/` aufs Portal geleitet.
- Modify `resources/js/components/AppSidebar.vue` — rollenabhängige Navigation (Admin vs. Portal).
- Test `tests/Feature/Portal/RegistryPortalTest.php`, `tests/Feature/Portal/PortalTokenTest.php`, `tests/Unit/SetupSnippetBuilderTest.php`, `tests/Unit/RegistryUrlTest.php`.

---

### Task K1: RegistryUrl-Service (Basis-URL & Auth-Host einer Gruppe)

Das Portal ist kein Registry-Request, daher steht die `registryBaseUrl()`-Trait-Methode (braucht Request + `registryDomainMode`) nicht zur Verfügung. Diese Service kapselt die Ableitung standalone: hat die Gruppe eine Domain, liegt die Registry an deren Wurzel; sonst unter `config('app.url')/r/{slug}`.

**Files:**
- Create: `app/Services/Registry/RegistryUrl.php`
- Test: `tests/Unit/RegistryUrlTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\Domain;
use App\Models\Group;
use App\Services\Registry\RegistryUrl;

it('uses the app url with slug path when the group has no domain', function () {
    config(['app.url' => 'https://reg.example.test']);
    $group = Group::factory()->create(['slug' => 'acme']);

    $url = app(RegistryUrl::class);
    expect($url->base($group))->toBe('https://reg.example.test/r/acme');
    expect($url->host($group))->toBe('reg.example.test');
    expect($url->pathPrefix($group))->toBe('/r/acme');
});

it('uses the custom domain at its root when the group has one', function () {
    $group = Group::factory()->create(['slug' => 'acme']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.acme.test']);

    $url = app(RegistryUrl::class);
    expect($url->base($group->fresh()))->toBe('https://packages.acme.test');
    expect($url->host($group->fresh()))->toBe('packages.acme.test');
    expect($url->pathPrefix($group->fresh()))->toBe('');
});
```

- [ ] **Step 2: Run — expect fail**

Run: `vendor/bin/pest tests/Unit/RegistryUrlTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Registry;

use App\Models\Group;

class RegistryUrl
{
    /** Vollständige Basis-URL der Registry (ohne abschließenden Slash). */
    public function base(Group $group): string
    {
        $domain = $group->domains->first();

        if ($domain !== null) {
            return 'https://'.$domain->hostname;
        }

        return rtrim((string) config('app.url'), '/').'/r/'.$group->slug;
    }

    /** Host-Teil für auth.json / .npmrc (ohne Schema, ohne Pfad). */
    public function host(Group $group): string
    {
        return (string) parse_url($this->base($group), PHP_URL_HOST);
    }

    /** Pfad-Präfix: leer bei Custom-Domain, sonst /r/{slug}. */
    public function pathPrefix(Group $group): string
    {
        return $group->domains->isNotEmpty() ? '' : '/r/'.$group->slug;
    }
}
```

- [ ] **Step 4: Run — expect pass**

Run: `vendor/bin/pest tests/Unit/RegistryUrlTest.php`
Expected: PASS. Then `vendor/bin/pint app/Services/Registry/RegistryUrl.php` and `vendor/bin/phpstan analyse app/Services/Registry/RegistryUrl.php`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Registry/RegistryUrl.php tests/Unit/RegistryUrlTest.php
git commit -m "feat: registry url resolver for portal (domain or slug path)"
```

---

### Task K2: SetupSnippetBuilder (composer/auth/npm Snippets)

Erzeugt aus einer Gruppe die Copy-Paste-Blöcke fürs Kunden-Setup. Composer bekommt einen `repositories`-Block + einen `auth.json`-Block; npm eine `.npmrc` mit `registry`- und `_authToken`-Zeile. Der eigentliche Token wird NICHT eingesetzt (Platzhalter `<dein-token>`), da Tokens nur einmal im Klartext existieren.

**Files:**
- Create: `app/Services/Registry/SetupSnippetBuilder.php`
- Test: `tests/Unit/SetupSnippetBuilderTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\Group;
use App\Services\Registry\RegistryUrl;
use App\Services\Registry\SetupSnippetBuilder;

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
});

it('builds composer, auth and npm snippets for a slug-based registry', function () {
    $group = Group::factory()->create(['slug' => 'acme']);
    $snips = (new SetupSnippetBuilder(app(RegistryUrl::class)))->for($group->fresh());

    expect($snips['composer'])->toContain('"type": "composer"')
        ->toContain('https://reg.example.test/r/acme');
    expect($snips['auth'])->toContain('reg.example.test')
        ->toContain('<dein-token>');
    expect($snips['npm'])->toContain('registry=https://reg.example.test/r/acme/')
        ->toContain('//reg.example.test/r/acme/:_authToken=<dein-token>');
});
```

- [ ] **Step 2: Run — expect fail**

Run: `vendor/bin/pest tests/Unit/SetupSnippetBuilderTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Registry;

use App\Models\Group;

class SetupSnippetBuilder
{
    public function __construct(private RegistryUrl $url) {}

    /**
     * @return array{composer: string, auth: string, npm: string}
     */
    public function for(Group $group): array
    {
        $base = $this->url->base($group);
        $host = $this->url->host($group);
        $prefix = $this->url->pathPrefix($group);
        // npm-Zeilen adressieren den Host inkl. Pfad-Präfix; mit Slash abgeschlossen.
        $npmBase = $host.$prefix.'/';

        return [
            'composer' => json_encode([
                'repositories' => [
                    ['type' => 'composer', 'url' => $base],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),

            'auth' => json_encode([
                'http-basic' => [
                    $host => ['username' => 'token', 'password' => '<dein-token>'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),

            'npm' => "registry={$base}/\n//{$npmBase}:_authToken=<dein-token>",
        ];
    }
}
```

- [ ] **Step 4: Run — expect pass**

Run: `vendor/bin/pest tests/Unit/SetupSnippetBuilderTest.php`
Expected: PASS. Then Pint + PHPStan on the new file.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Registry/SetupSnippetBuilder.php tests/Unit/SetupSnippetBuilderTest.php
git commit -m "feat: setup snippet builder for composer/npm client config"
```

---

### Task K3: Policies für Org-Grenze

Zentrale Autorisierung: ein User darf eine Gruppe/einen Token nur sehen bzw. löschen, wenn sie zur eigenen Organization gehören. Operator-Admins (`is_operator`-Org, Rolle admin) dürfen alles — sie nutzen ohnehin das Admin-GUI, aber die Policy soll sie nicht aussperren.

**Files:**
- Create: `app/Policies/GroupPolicy.php`
- Create: `app/Policies/RegistryTokenPolicy.php`
- Test: `tests/Unit/PortalPolicyTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;

it('lets a member view and manage only their own org resources', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $member = User::factory()->for($orgA)->create(['role' => UserRole::Member]);

    $ownGroup = Group::factory()->for($orgA)->create();
    $otherGroup = Group::factory()->for($orgB)->create();
    $ownToken = RegistryToken::factory()->for($orgA)->create();
    $otherToken = RegistryToken::factory()->for($orgB)->create();

    expect($member->can('view', $ownGroup))->toBeTrue();
    expect($member->can('view', $otherGroup))->toBeFalse();
    expect($member->can('delete', $ownToken))->toBeTrue();
    expect($member->can('delete', $otherToken))->toBeFalse();
});

it('lets an operator admin view any group', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($operator)->create(['role' => UserRole::Admin]);
    $foreignGroup = Group::factory()->for(Organization::factory()->create())->create();

    expect($admin->can('view', $foreignGroup))->toBeTrue();
});
```

If `RegistryToken` / `Group` have no factory, create minimal ones under `database/factories/`. Check first with `ls database/factories/`.

- [ ] **Step 2: Run — expect fail**

Run: `vendor/bin/pest tests/Unit/PortalPolicyTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement policies**

`app/Policies/GroupPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function view(User $user, Group $group): bool
    {
        return $this->operatorAdmin($user) || $group->organization_id === $user->organization_id;
    }

    private function operatorAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin && (bool) $user->organization?->is_operator;
    }
}
```

`app/Policies/RegistryTokenPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\RegistryToken;
use App\Models\User;

class RegistryTokenPolicy
{
    public function delete(User $user, RegistryToken $token): bool
    {
        return $this->operatorAdmin($user) || $token->organization_id === $user->organization_id;
    }

    private function operatorAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin && (bool) $user->organization?->is_operator;
    }
}
```

Laravel 12 auto-discovers policies by naming convention (`Model` → `ModelPolicy`); no manual registration needed. Verify the `Group`/`RegistryToken` models live in `App\Models`.

- [ ] **Step 4: Run — expect pass**

Run: `vendor/bin/pest tests/Unit/PortalPolicyTest.php`
Expected: PASS. Pint + PHPStan on both policy files.

- [ ] **Step 5: Commit**

```bash
git add app/Policies/ tests/Unit/PortalPolicyTest.php database/factories/
git commit -m "feat: group and token policies enforcing org boundary"
```

---

### Task K4: Portal-RegistryController + Vue-Seiten

Index listet die Registries der eigenen Org (mit Domain/URL + Paketzahl). Show liefert Detail: Setup-Snippets, Paketliste (read-only), und die Tokens der Gruppe. Alle Queries gehen von `$request->user()->organization` aus; `show` autorisiert zusätzlich per `GroupPolicy@view`.

**Files:**
- Create: `app/Http/Controllers/Portal/RegistryController.php`
- Create: `resources/js/pages/portal/Registries.vue`
- Create: `resources/js/pages/portal/Registry.vue`
- Modify: `routes/web.php`
- Test: `tests/Feature/Portal/RegistryPortalTest.php`

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
    $this->orgA = Organization::factory()->create();
    $this->member = User::factory()->for($this->orgA)->create(['role' => UserRole::Member]);
});

it('lists only the members own registries', function () {
    $mine = Group::factory()->for($this->orgA)->create(['name' => 'Acme Registry', 'slug' => 'acme']);
    Group::factory()->for(Organization::factory()->create())->create(['name' => 'Foreign']);

    $this->actingAs($this->member)->get('/portal')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registries')
            ->has('registries', 1)
            ->where('registries.0.name', 'Acme Registry'));
});

it('shows a registry with setup snippets and its packages', function () {
    $group = Group::factory()->for($this->orgA)->create(['slug' => 'acme']);
    $pkg = Package::factory()->create(['name' => 'acme/widget']);
    $group->packages()->attach($pkg);

    $this->actingAs($this->member)->get("/portal/registries/{$group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registry')
            ->where('registry.slug', 'acme')
            ->where('snippets.composer', fn ($v) => str_contains($v, '/r/acme'))
            ->has('packages', 1)
            ->where('packages.0.name', 'acme/widget'));
});

it('forbids viewing a foreign registry', function () {
    $foreign = Group::factory()->for(Organization::factory()->create())->create();

    $this->actingAs($this->member)->get("/portal/registries/{$foreign->id}")->assertForbidden();
});

it('redirects guests to login', function () {
    $group = Group::factory()->for($this->orgA)->create();
    $this->get("/portal/registries/{$group->id}")->assertRedirect('/login');
});
```

- [ ] **Step 2: Run — expect fail**

Run: `vendor/bin/pest tests/Feature/Portal/RegistryPortalTest.php`
Expected: FAIL (route missing).

- [ ] **Step 3: Implement controller**

`app/Http/Controllers/Portal/RegistryController.php`:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Package;
use App\Services\Registry\RegistryUrl;
use App\Services\Registry\SetupSnippetBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistryController extends Controller
{
    public function __construct(private RegistryUrl $url, private SetupSnippetBuilder $snippets) {}

    public function index(Request $request): Response
    {
        $groups = Group::where('organization_id', $request->user()->organization_id)
            ->with('domains')
            ->withCount('packages')
            ->orderBy('name')
            ->get();

        return Inertia::render('portal/Registries', [
            'registries' => $groups->map(fn (Group $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'slug' => $g->slug,
                'url' => $this->url->base($g),
                'packages_count' => $g->packages_count,
            ]),
        ]);
    }

    public function show(Request $request, Group $group): Response
    {
        $this->authorize('view', $group);
        $group->load('domains');

        $packages = $group->packages()
            ->with(['versions' => fn ($q) => $q->latest('released_at')->limit(1)])
            ->orderBy('name')
            ->get();

        return Inertia::render('portal/Registry', [
            'registry' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'url' => $this->url->base($group),
            ],
            'snippets' => $this->snippets->for($group),
            'packages' => $packages->map(fn (Package $p) => [
                'name' => $p->name,
                'type' => $p->type->value,
                'description' => $p->description,
                'latest_version' => $p->versions->first()?->version_pretty,
            ]),
        ]);
    }
}
```

Note: `authorize()` requires `AuthorizesRequests`. Confirm `app/Http/Controllers/Controller.php` uses the trait (Laravel 12 base does by default via `Illuminate\Foundation\Auth\Access\AuthorizesRequests`). If not, add `use AuthorizesRequests;` to the base controller.

- [ ] **Step 4: Add routes** in `routes/web.php` (before `require` lines):

```php
Route::middleware(['auth', 'verified'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [App\Http\Controllers\Portal\RegistryController::class, 'index'])->name('registries.index');
    Route::get('registries/{group}', [App\Http\Controllers\Portal\RegistryController::class, 'show'])->name('registries.show');
});
```

- [ ] **Step 5: Create Vue pages** `resources/js/pages/portal/Registries.vue` and `resources/js/pages/portal/Registry.vue`.

`Registries.vue` — Karten-Grid der Registries mit Name, URL, Paketzahl, Link auf Detail. Nutze `AppLayout` und die shadcn-`Card`-Komponenten analog zu `resources/js/pages/admin/groups/Index.vue` (Muster dort ansehen). Props: `registries: Array<{id,name,slug,url,packages_count}>`.

`Registry.vue` — Kopf mit Registry-Name + URL; drei Snippet-Blöcke (composer, auth.json, .npmrc) je in einem `<pre>` mit Copy-Button; darunter Paketliste als Tabelle (Name, Typ, letzte Version, Beschreibung). Props: `registry`, `snippets: {composer,auth,npm}`, `packages`. Copy-Button:

```vue
<script setup lang="ts">
const copy = (text: string) => navigator.clipboard.writeText(text)
</script>
```

Halte den sichtbaren Text neutral (kein Tech-Jargon in Überschriften — „Registry-Adresse", „Zugang einrichten"). Die Snippet-Inhalte selbst sind technisch und dürfen composer/npm benennen.

- [ ] **Step 6: Run tests + build**

Run: `vendor/bin/pest tests/Feature/Portal/RegistryPortalTest.php` → PASS.
Run: `npm run build` → no errors. Pint + PHPStan.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Portal/RegistryController.php resources/js/pages/portal/ routes/web.php tests/Feature/Portal/RegistryPortalTest.php app/Http/Controllers/Controller.php
git commit -m "feat: customer portal registry overview and detail with setup snippets"
```

---

### Task K5: Portal-Token-Selbstverwaltung (org-scoped)

Der Kunde legt eigene Tokens an und widerruft sie — hart auf die eigene Org gescoped (schließt den TODO(multi-tenant) für den Portal-Pfad). `organization_id` kommt NIE aus dem Request, sondern immer aus `$request->user()->organization`. Die optionale Registry muss der eigenen Org gehören.

**Files:**
- Create: `app/Http/Controllers/Portal/TokenController.php`
- Create: `app/Http/Requests/Portal/StorePortalTokenRequest.php`
- Modify: `resources/js/pages/portal/Registry.vue` (Token-Panel)
- Modify: `routes/web.php`
- Test: `tests/Feature/Portal/PortalTokenTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;

beforeEach(function () {
    $this->orgA = Organization::factory()->create();
    $this->member = User::factory()->for($this->orgA)->create(['role' => UserRole::Member]);
});

it('creates a token scoped to the members own org and returns the plaintext once', function () {
    $group = Group::factory()->for($this->orgA)->create();

    $this->actingAs($this->member)->from('/portal')
        ->post('/portal/tokens', ['name' => 'CI', 'group_id' => $group->id, 'ability' => 'read'])
        ->assertRedirect('/portal')
        ->assertSessionHas('plainTextToken');

    $token = RegistryToken::first();
    expect($token->organization_id)->toBe($this->orgA->id)
        ->and($token->group_id)->toBe($group->id)
        ->and($token->ability)->toBe(TokenAbility::Read);
});

it('rejects assigning a token to a foreign registry', function () {
    $foreign = Group::factory()->for(Organization::factory()->create())->create();

    $this->actingAs($this->member)->from('/portal')
        ->post('/portal/tokens', ['name' => 'CI', 'group_id' => $foreign->id])
        ->assertSessionHasErrors('group_id');

    expect(RegistryToken::count())->toBe(0);
});

it('revokes only own-org tokens', function () {
    $own = RegistryToken::factory()->for($this->orgA)->create();
    $foreign = RegistryToken::factory()->for(Organization::factory()->create())->create();

    $this->actingAs($this->member)->delete("/portal/tokens/{$foreign->id}")->assertForbidden();
    expect(RegistryToken::find($foreign->id))->not->toBeNull();

    $this->actingAs($this->member)->from('/portal')->delete("/portal/tokens/{$own->id}")->assertRedirect('/portal');
    expect(RegistryToken::find($own->id))->toBeNull();
});
```

- [ ] **Step 2: Run — expect fail**

Run: `vendor/bin/pest tests/Feature/Portal/PortalTokenTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement request** `app/Http/Requests/Portal/StorePortalTokenRequest.php`:

```php
<?php

namespace App\Http\Requests\Portal;

use App\Enums\TokenAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePortalTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'group_id' => [
                'nullable',
                'uuid',
                // Nur Gruppen der eigenen Org zulassen — verhindert Zuweisung an fremde Registry.
                Rule::exists('groups', 'id')->where('organization_id', $this->user()->organization_id),
            ],
            'ability' => ['nullable', Rule::enum(TokenAbility::class)],
        ];
    }
}
```

- [ ] **Step 4: Implement controller** `app/Http/Controllers/Portal/TokenController.php`:

```php
<?php

namespace App\Http\Controllers\Portal;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StorePortalTokenRequest;
use App\Models\Group;
use App\Models\RegistryToken;
use Illuminate\Http\RedirectResponse;

class TokenController extends Controller
{
    public function store(StorePortalTokenRequest $request): RedirectResponse
    {
        $group = $request->validated('group_id')
            ? Group::findOrFail($request->validated('group_id'))
            : null;

        [$token, $plain] = RegistryToken::issue(
            $request->user()->organization,
            $request->validated('name'),
            $group,
            $request->enum('ability', TokenAbility::class) ?? TokenAbility::Read,
        );

        return back()->with('plainTextToken', $plain)->with('success', "Token {$token->name} erstellt.");
    }

    public function destroy(RegistryToken $token): RedirectResponse
    {
        $this->authorize('delete', $token);
        $token->delete();

        return back()->with('success', 'Token widerrufen.');
    }
}
```

- [ ] **Step 5: Add routes** in the `/portal` group from K4:

```php
Route::post('tokens', [App\Http\Controllers\Portal\TokenController::class, 'store'])->name('tokens.store');
Route::delete('tokens/{token}', [App\Http\Controllers\Portal\TokenController::class, 'destroy'])->name('tokens.destroy');
```

- [ ] **Step 6: Token-Panel in `Registry.vue`** — Formular (Name, Ability-Select read/publish, verstecktes `group_id` = aktuelle Registry) das auf `portal.tokens.store` postet; Liste der Tokens dieser Gruppe mit Revoke-Button auf `portal.tokens.destroy`; frisch erstellter `plainTextToken` aus den Inertia-Flash-Props einmalig prominent anzeigen (Muster: `resources/js/pages/admin/tokens/Index.vue`). Ergänze in `RegistryController@show` die Tokens der Gruppe zu den Props:

```php
'tokens' => $group->tokens()->latest()->get()->map(fn ($t) => [
    'id' => $t->id,
    'name' => $t->name,
    'ability' => $t->ability->value,
    'last_used_at' => $t->last_used_at?->diffForHumans(),
]),
```

Passe den `Registry.vue`-Test-Erwartungswert nicht an — die bestehenden Assertions bleiben gültig (Tokens sind additive Props).

- [ ] **Step 7: Run tests + build**

Run: `vendor/bin/pest tests/Feature/Portal/` → PASS. `npm run build`, Pint, PHPStan.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Portal/TokenController.php app/Http/Requests/Portal/ resources/js/pages/portal/Registry.vue app/Http/Controllers/Portal/RegistryController.php routes/web.php tests/Feature/Portal/PortalTokenTest.php
git commit -m "feat: customer self-service token management scoped to own org"
```

---

### Task K6: Rollenbasierte Navigation + Member-Landing

Members sehen die Portal-Navigation (nicht die Admin-Links) und landen von `/` direkt im Portal. Admin/Maintainer behalten Admin + können das Portal ihrer Operator-Org sehen.

**Files:**
- Modify: `resources/js/components/AppSidebar.vue`
- Modify: `routes/web.php` (Redirect `member` von `/` bzw. `/dashboard`)
- Test: `tests/Feature/Portal/PortalNavigationTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

it('sends a member from the dashboard to the portal', function () {
    $member = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member, 'email_verified_at' => now()]);

    $this->actingAs($member)->get('/dashboard')->assertRedirect('/portal');
});

it('keeps an admin on the dashboard', function () {
    $admin = User::factory()->for(Organization::factory())->create(['role' => UserRole::Admin, 'email_verified_at' => now()]);

    $this->actingAs($admin)->get('/dashboard')->assertOk();
});
```

- [ ] **Step 2: Run — expect fail**

Run: `vendor/bin/pest tests/Feature/Portal/PortalNavigationTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement redirect** — ersetze die `dashboard`-Closure in `routes/web.php`:

```php
Route::get('dashboard', function () {
    if (request()->user()->role === App\Enums\UserRole::Member) {
        return redirect()->route('portal.registries.index');
    }

    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');
```

- [ ] **Step 4: Nav** — in `AppSidebar.vue` die Nav-Items aus `page.props.auth.user.role` ableiten: für `member` nur ein Portal-Item (`portal.registries.index`), für admin/maintainer die bestehenden Admin-Items plus optional einen Portal-Link. Nutze das bestehende Inertia-`usePage()`-Muster der Komponente.

- [ ] **Step 5: Run tests + build**

Run: `vendor/bin/pest tests/Feature/Portal/PortalNavigationTest.php` → PASS. `npm run build`.

- [ ] **Step 6: Commit**

```bash
git add resources/js/components/AppSidebar.vue routes/web.php tests/Feature/Portal/PortalNavigationTest.php
git commit -m "feat: role-based navigation, redirect members to portal"
```

---

### Task K7: E2E-Portal-Isolationstest

Ein durchgängiger Test, der die Mandanten-Isolation als Ganzes beweist: zwei Kunden-Orgs, jeder sieht nur seins, Token-Selbstverwaltung funktioniert, Cross-Org ist überall dicht.

**Files:**
- Test: `tests/Feature/Portal/PortalIsolationTest.php`

- [ ] **Step 1: Test**

```php
<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

it('fully isolates two customers across list, detail, snippets and tokens', function () {
    config(['app.url' => 'https://reg.example.test']);

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $memberA = User::factory()->for($orgA)->create(['role' => UserRole::Member]);

    $groupA = Group::factory()->for($orgA)->create(['slug' => 'acme']);
    $groupB = Group::factory()->for($orgB)->create(['slug' => 'other']);
    $pkg = Package::factory()->create(['name' => 'acme/widget']);
    $groupA->packages()->attach($pkg);

    // Übersicht: nur eigene Registry
    $this->actingAs($memberA)->get('/portal')
        ->assertInertia(fn ($p) => $p->has('registries', 1)->where('registries.0.slug', 'acme'));

    // Detail eigen: Snippets + Paket sichtbar
    $this->actingAs($memberA)->get("/portal/registries/{$groupA->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('snippets.npm', fn ($v) => str_contains($v, '/r/acme/'))->has('packages', 1));

    // Detail fremd: verboten
    $this->actingAs($memberA)->get("/portal/registries/{$groupB->id}")->assertForbidden();

    // Token für eigene Registry: ok
    $this->actingAs($memberA)->from('/portal')
        ->post('/portal/tokens', ['name' => 'CI', 'group_id' => $groupA->id])->assertRedirect('/portal');

    // Token für fremde Registry: abgelehnt
    $this->actingAs($memberA)->from('/portal')
        ->post('/portal/tokens', ['name' => 'evil', 'group_id' => $groupB->id])->assertSessionHasErrors('group_id');
});
```

- [ ] **Step 2: Run full suite**

Run: `vendor/bin/pest` → all green. `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `npm run build`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Portal/PortalIsolationTest.php
git commit -m "test: end-to-end customer portal tenant isolation"
```

---

## Self-Review

- **Spec-Abdeckung §9 Kunden-Portal:** eigene Registries (K4) ✓, Setup-Snippets composer/npm (K2/K4) ✓, Token-Selbstverwaltung (K5) ✓, Paketliste mit Versionen (K4) ✓. §6 Org-Member read-only + eigene Tokens (K3/K5) ✓.
- **Isolation:** jede Query org-scoped, Policies zentral, E2E-Isolationstest (K7).
- **Öffentliche Texte:** UI-Überschriften neutral gehalten (K4-Hinweis); technische Snippet-Inhalte dürfen benennen.
- **Offen/verschoben:** Readmes pro Paket (Spec nennt „Readmes") — Package hat noch kein readme-Feld; bewusst außen vor, bis ein Readme-Sync existiert (Follow-up notieren). OIDC/Passkeys/TOTP bleibt eigene Phase v0.7.

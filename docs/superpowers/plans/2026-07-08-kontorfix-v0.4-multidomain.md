# Kontorfix v0.4 — Multi-Domain-Routing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Jede Gruppe unter einer oder mehreren frei konfigurierbaren eigenen Domains erreichbar machen — `https://packages.kadenz.de/packages.json` bedient dieselbe Registry wie `https://registry.noixdev.de/r/kadenz/packages.json`, nur unter der Domain-Wurzel.

**Architecture:** Eine Gruppe wird pro Request auf zwei Wegen aufgelöst: über den Slug-Pfad (`/r/{group:slug}/...`, wie bisher) oder über den **Host-Header** (Custom-Domain → `domains`-Tabelle → Gruppe). Ein `RegistryContext` (Gruppe + `registryBaseUrl`) wird einheitlich ermittelt und in Request-Attribute gelegt; die Controller lesen ihn statt einen fest gebundenen `Group`-Parameter zu erwarten. Alle URL-Builder erhalten die **volle Registry-Basis-URL** (inkl. korrektem Pfad-Präfix — `/r/{slug}` bei Slug-Zugriff, leer bei Domain-Zugriff) statt `/r/{slug}` hart zu codieren. Custom-Domain-Routen werden auf Root-Ebene registriert, aber per Middleware auf bekannte Registry-Domains beschränkt, sodass sie die Haupt-App nie überschatten.

**Tech Stack:** wie v0.3. Kein neues Paket.

**Spec:** docs/superpowers/specs/2026-07-08-kontorfix-design.md §4 (Domain: Host→Gruppen-Mapping), §1 Ziel „Multi-Domain-Support pro Registry".

**Konventionen:** wie bisher — Conventional Commits + Footer `Co-Authored-By: Claude <noreply@anthropic.com>`; `ddev php artisan test --compact`; Pint/Larastan-level-6-clean (`ddev exec 'cd /var/www/html && vendor/bin/phpstan analyse --no-progress'`); `ddev npm run build`/`ddev npm run lint` für GUI; vor voller Suite `ddev exec 'rm -f /tmp/kfx-dist-* 2>/dev/null'`; **vor jedem Commit `git symbolic-ref --short HEAD` == `main` prüfen**; nicht pushen; `docs/`/`.claude/` nie anfassen; Larastan-Generics überall.

---

## Betroffene URL-Konstruktion (heute mit hartem `/r/{slug}`)

```
app/Services/Composer/ComposerMetadataBuilder.php:30   dist url
app/Services/Upstream/ComposerProxyService.php:48       proxy dist url
app/Services/Npm/NpmMetadataBuilder.php:28              tarball url
app/Services/Upstream/NpmProxyService.php:47            proxy tarball url
app/Http/Controllers/Registry/ComposerController.php    metadata-url (root)
```

---

### Task D1: URL-Builder auf eine übergebene Registry-Basis-URL umstellen (reiner Refactor)

**Files:**
- Modify: `ComposerMetadataBuilder`, `NpmMetadataBuilder`, `ComposerProxyService`, `NpmProxyService`, `ComposerController`, `NpmController`, `ProxyDownloadController`
- Test: bestehende Tests bleiben grün; ein neuer Unit-Test pro Builder für die neue Signatur.

Ziel: die Builder bekommen `string $registryBaseUrl` (z.B. `https://host/r/kadenz` ODER `https://packages.kadenz.de`) und hängen NUR noch die Endpoint-Pfade an — kein `/r/{slug}` mehr im Builder. Die Controller berechnen die Basis vorerst weiterhin als `{scheme://host}/r/{slug}` (Verhalten unverändert), sodass alle bestehenden Tests grün bleiben.

- [ ] **Step 1:** Signaturen ändern:
  - `ComposerMetadataBuilder::build(Package, Group, string $registryBaseUrl)` — dist url = `{$registryBaseUrl}/dists/{$package->name}/{$v->version}.zip` (Group nur noch für Versionsdaten, nicht für die URL).
  - `NpmMetadataBuilder::build(Package, string $registryBaseUrl)` (der `$groupSlug`-Parameter entfällt) — tarball = `{$registryBaseUrl}/{$package->name}/-/{$v->dist_tarball_name}`.
  - `ComposerProxyService::metadata(Group, Upstream, string $packageName, string $registryBaseUrl)` — proxy dist url = `{$registryBaseUrl}/proxy/composer/{$upstream->id}/{$packageName}/{$identifier}`.
  - `NpmProxyService::packument(Group, Upstream, string $packageName, string $registryBaseUrl)` — `{$registryBaseUrl}/proxy/npm/{$upstream->id}/{$packageName}/-/{$file}`.
- [ ] **Step 2:** Controller: einen Trait-Helfer `registryBaseUrl(Request, Group): string` in `ResolvesRegistryPackage` einführen, der VORERST `"{$request->getSchemeAndHttpHost()}/r/{$group->slug}"` liefert. Alle Builder-Aufrufe in `ComposerController`/`NpmController`/`ProxyDownloadController` auf `$this->registryBaseUrl($request, $group)` umstellen. `metadata-url` im Composer-`root` bleibt zunächst `"/r/{$group->slug}/p2/%package%.json"` (wird in D2 kontextabhängig).
- [ ] **Step 3:** Bestehende Builder-Unit-Tests an die neue Signatur anpassen (die erwarteten URLs bleiben identisch, nur der übergebene Parameter ändert sich). `ddev php artisan test --compact` komplett grün.
- [ ] **Step 4:** pint, phpstan `[OK]`. **Step 5: Commit** — `refactor: builders take a full registry base url instead of hardcoding /r/{slug}`

---

### Task D2: Domain-Auflösung + Root-Routen für Custom-Domains

**Files:**
- Create: `app/Http/Middleware/ResolveRegistryDomain.php`, `app/Http/Middleware/EnsureRegistryDomain.php`
- Modify: `bootstrap/app.php` (Middleware-Aliasse + globales Domain-Resolve), `routes/registry.php` (Domain-Root-Routen), `ResolvesRegistryPackage` (Kontext-Auflösung), die Registry-Controller (Gruppe aus Kontext statt Route-Binding)
- Test: `tests/Feature/Registry/CustomDomainTest.php`

**Kontext-Auflösung (Kern):** Ein Trait-Helfer `registryGroup(Request, ?Group $bound): Group` liefert die per Slug gebundene Gruppe ODER die per Host aufgelöste (Request-Attribut `registryGroup`). `registryBaseUrl(Request, Group)` liefert bei Domain-Zugriff `{scheme://host}` (kein `/r/{slug}`), sonst `{scheme://host}/r/{slug}` — anhand eines Request-Attributs `registryDomainMode`.

**Routing:** Zwei Wege, dieselben Controller-Methoden:
- Slug (bestehend): `/r/{group:slug}/...`.
- Domain: dieselben Pfade auf Root-Ebene innerhalb `Route::domain('{registryHost}')->middleware([SubstituteBindings, 'registry.domain', 'registry.auth'])`, registriert NACH web+slug. `registry.domain` = `EnsureRegistryDomain`: löst `Domain::where('hostname', $request->host())` → Gruppe, abort(404) wenn unbekannt (schützt die Haupt-App: fremde Hosts/Pfade fallen sauber durch bzw. 404). Setzt `registryGroup` + `registryDomainMode=true`.

Da die Domain-Routen KEINEN `{group}`-Slug-Parameter haben, müssen die Controller die Gruppe über `registryGroup(Request, $group = null)` beziehen. Umbau: Controller-Signaturen behalten `?Group $group = null` (Slug bindet, Domain = null) und lösen intern via Trait auf. Die Route-Parameter der Domain-Variante spiegeln die der Slug-Variante ohne `{group}`.

- [ ] **Step 1: Failing test**

```php
<?php // tests/Feature/Registry/CustomDomainTest.php
use App\Enums\PackageType;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;

it('serves the composer root at the domain root with a root-relative metadata-url', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    $pkg = Package::factory()->create(['type' => PackageType::Composer, 'name' => 'acme/demo']);
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(array_merge(tokenHeaderFor($group), ['Host' => 'packages.kadenz.test']))
        ->getJson('http://packages.kadenz.test/packages.json');

    $res->assertOk()->assertJsonPath('metadata-url', '/p2/%package%.json');
});

it('serves p2 metadata with domain-root dist urls', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    $pkg = Package::factory()->create(['type' => PackageType::Composer, 'name' => 'acme/demo']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0.0', 'version_pretty' => 'v1.0.0']);
    $group->packages()->attach($pkg);

    $doc = $this->withHeaders(array_merge(tokenHeaderFor($group), ['Host' => 'packages.kadenz.test']))
        ->getJson('http://packages.kadenz.test/p2/acme/demo.json')->assertOk()->json();
    $v = \Composer\MetadataMinifier\MetadataMinifier::expand($doc['packages']['acme/demo'])[0];

    expect($v['dist']['url'])->toBe('http://packages.kadenz.test/dists/acme/demo/1.0.0.0.zip');
});

it('serves an npm packument at the domain root', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'npm.kadenz.test']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad', 'dist_tags' => ['latest' => '1.0.0']]);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => ['name' => 'leftpad'], 'dist_tarball_name' => 'leftpad-1.0.0.tgz']);
    $group->packages()->attach($pkg);

    $doc = $this->withHeaders(array_merge(tokenHeaderFor($group), ['Host' => 'npm.kadenz.test']))
        ->getJson('http://npm.kadenz.test/leftpad')->assertOk()->json();

    expect($doc['versions']['1.0.0']['dist']['tarball'])->toBe('http://npm.kadenz.test/leftpad/-/leftpad-1.0.0.tgz');
});

it('404s an unknown host without leaking the app', function () {
    $this->getJson('http://not-a-registry.test/packages.json')->assertNotFound();
});

it('still serves the slug route unchanged', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Composer, 'name' => 'acme/demo']);
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/packages.json')
        ->assertOk()->assertJsonPath('metadata-url', '/r/kadenz/p2/%package%.json');
});
```

- [ ] **Step 2: FAIL**, **Step 3: Implement** middleware + routes + trait-Auflösung + Controller-Umbau (Gruppe aus Kontext, `metadata-url` kontextabhängig: bei Domain `/p2/%package%.json`, bei Slug `/r/{slug}/p2/%package%.json`). Achte darauf, dass die Domain-Root-Routen die npm-`/{package}`-Catch-all NUR auf Registry-Hosts greifen (durch `EnsureRegistryDomain` + Registrierungsreihenfolge nach web/slug) — der „404s an unknown host"-Test beweist, dass die Haupt-App nicht überschattet wird.
- [ ] **Step 4:** volle Suite grün (alle bestehenden Slug-Tests + neue Domain-Tests), pint, phpstan.
- [ ] **Step 5: Commit** — `feat: resolve registry groups by custom domain and serve at the domain root`

---

### Task D3: Admin-GUI — Domains pro Gruppe verwalten

**Files:**
- Create: `app/Http/Controllers/Admin/DomainController.php`, `app/Http/Requests/Admin/StoreDomainRequest.php`, Vue-Anbindung in der Gruppen-GUI (Domains je Gruppe hinzufügen/entfernen)
- Modify: `routes/web.php`, `app/Http/Controllers/Admin/GroupController.php` (Domains im Index bereits vorhanden — jetzt beschreibbar), `GroupSheet.vue`/`groups/Index.vue`
- Test: `tests/Feature/Admin/DomainCrudTest.php`

- [ ] CRUD für Domains (store/destroy, an eine Gruppe gebunden): hostname required, gültiges Hostname-Format (`regex` für FQDN), `unique:domains,hostname`. Role-gated. Tests: hinzufügen/validieren (ungültiger Host, Duplikat)/löschen, member 403. GUI: in der Gruppen-Tabelle/Detailansicht Domains anzeigen + ein kleines Formular zum Hinzufügen/Entfernen (etablierter Stil). `ddev npm run build` + `ddev npm run lint` grün.
- [ ] **Commit** — `feat: domain management gui per group`

---

### Task D4: E2E-Contract-Test + realer Multi-Domain-Smoke-Test

**Files:**
- Test: `tests/Feature/Registry/CustomDomainFlowTest.php`

- [ ] **Step 1:** Ende-zu-Ende über eine Custom-Domain: composer root → p2 → dist (Fake-VCS) UND npm packument → tarball, jeweils unter dem Domain-Root; plus ein Proxy-Fall unter Custom-Domain (die Proxy-URLs müssen ebenfalls domain-root sein).
- [ ] **Step 2: grün.**
- [ ] **Step 3: Realer Smoke-Test (dokumentieren):** eine Domain (z.B. `packages.kadenz.ddev.site` — als zusätzlicher DDEV-Hostname via `ddev config --additional-hostnames`) auf eine Gruppe zeigen lassen und `composer require`/`npm install` gegen die Domain-Wurzel laufen lassen. Ergebnis als Kommentarblock festhalten. (Falls der zusätzliche DDEV-Hostname im Rahmen nicht einrichtbar ist: mit `curl --resolve` / `Host`-Header gegen den bestehenden Host testen und das dokumentieren.)
- [ ] **Step 4: Commit** — `test: end-to-end multi-domain registry flow`

---

## Self-Review (beim Schreiben)

1. **Spec-Coverage §4:** Host→Gruppen-Mapping (D2) ✓, mehrere frei konfigurierbare Domains pro Gruppe (Schema seit v0.1 + GUI D3) ✓, Registry unter Domain-Wurzel (D1/D2) ✓, Slug-Zugriff bleibt parallel erhalten (D2-Test) ✓.
2. **Kein Shadowing der Haupt-App:** Domain-Root-Routen (inkl. npm-Catch-all) greifen nur auf bekannten Registry-Hosts (`EnsureRegistryDomain` 404t fremde Hosts), registriert nach web/slug — der „unknown host 404"-Test sichert das ab.
3. **URL-Korrektheit:** alle generierten URLs (dist, tarball, proxy, metadata-url) sind im Domain-Modus domain-root-relativ, im Slug-Modus `/r/{slug}`-präfixiert — eine einzige `registryBaseUrl`-Quelle (D1) verhindert Divergenz.
4. **Sicherheit unverändert:** ACL (authorizeGroup/findAccessible), Dependency-Confusion-Schutz und SSRF-Härtung aus v0.3 bleiben; die Domain-Auflösung ändert nur, WIE die Gruppe bestimmt wird, nicht die Zugriffsprüfung. `registry.auth` läuft auf beiden Wegen.
5. **Typkonsistenz:** `registryBaseUrl(Request, Group): string`, `registryGroup(Request, ?Group): Group` einheitlich im Trait; Builder-Signaturen konsistent zwischen D1 (Einführung) und D2 (Nutzung).

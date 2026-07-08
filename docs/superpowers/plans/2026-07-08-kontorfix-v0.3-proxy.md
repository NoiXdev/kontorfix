# Kontorfix v0.3 — Proxy/Mirror Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Öffentliche Upstreams (packagist.org für Composer, registry.npmjs.org für npm) pro Gruppe proxien und cachen — sodass ein Client hinter einer Kontorfix-Gruppe sowohl private als auch öffentliche Pakete über EINEN Endpoint zieht, mit Strict-Mode-Allowlist gegen Dependency Confusion.

**Architecture:** Pro Gruppe konfigurierbare `Upstream`-Kanäle (Typ composer/npm, URL, Policy `proxy`|`strict`, optionale Auth). Wird ein Paket lokal nicht gefunden, fällt der Registry-Controller auf den Upstream durch: `UpstreamClient` holt Metadaten (Laravel `Http`), cached sie in der DB (`upstream_metadata_cache`, TTL) und schreibt Dist-/Tarball-URLs auf unsere eigenen Proxy-Download-Routen um. Beim ersten Download wird das Artefakt lazy in die artifacts-Disk gecacht (wie unsere eigenen Dists) und danach lokal ausgeliefert — Offline-Fähigkeit. Strict-Mode: nur explizit freigegebene Upstream-Pakete werden durchgelassen. Composer-`available-packages` entfällt bei aktivem Upstream (Client nutzt dann lazy `metadata-url`). Getestet mit `Http::fake`; realer Smoke-Test gegen packagist/npmjs am Ende.

**Tech Stack:** wie v0.2. Neu genutzt: `Illuminate\Support\Facades\Http` (mit `Http::fake` in Tests). Kein neues Composer-Paket.

**Spec:** docs/superpowers/specs/2026-07-08-kontorfix-design.md §5 (Proxy/Cache, Strict-Mode) + §2 Lektion #7 (Mirroring ist die Bug-Quelle Nr.1 → Contract-Tests gegen reale Upstream-Formen).

**Konventionen:** wie bisher — Conventional Commits + Footer `Co-Authored-By: Claude <noreply@anthropic.com>`; `ddev php artisan test --compact`; Pint/Larastan-level-6-clean; vor voller Suite `ddev exec 'rm -f /tmp/kfx-dist-* 2>/dev/null'`; **vor jedem Commit `git symbolic-ref --short HEAD` == `main` prüfen (sonst BLOCKED)**; nicht pushen; `docs/`/`.claude/` nie anfassen; Larastan-Generics auf allen Relationen; Reviews scheitern hart an Netzwerk in Tests → immer `Http::fake`.

---

## Dateistruktur (neu/geändert)

```
database/migrations/<ts>_create_upstreams_and_cache_tables.php   # upstreams, upstream_allowlist, upstream_metadata_cache
app/Enums/UpstreamPolicy.php            # proxy | strict
app/Models/Upstream.php                 # gehört zu Group; type/url/policy/auth
app/Models/UpstreamAllowedPackage.php   # strict-mode Freigaben
app/Services/Upstream/UpstreamClient.php        # HTTP-Fetch von Metadaten/Tarballs, Auth
app/Services/Upstream/ComposerProxyService.php  # p2-Metadaten proxien, dist-URLs umschreiben, cachen
app/Services/Upstream/NpmProxyService.php        # packument proxien, tarball-URLs umschreiben, cachen
app/Services/Upstream/UpstreamCache.php          # DB-Metadaten-Cache + artifacts-Artefakt-Cache
app/Models/Group.php                    # upstreams() HasMany
app/Http/Controllers/Registry/ComposerController.php  # metadata/dist Fallthrough + root ohne available-packages bei Upstream
app/Http/Controllers/Registry/NpmController.php       # packument/tarball Fallthrough
app/Http/Controllers/Registry/ProxyDownloadController.php  # /r/{group}/proxy/... Artefakt-Download (cache-on-first-hit)
app/Http/Controllers/Admin/UpstreamController.php + Requests   # GUI
resources/js/pages/admin/... (Upstream-Verwaltung in der Gruppen-GUI)
routes/registry.php, routes/web.php
tests/Feature/Registry/{ComposerProxyTest,NpmProxyTest,ProxyDownloadTest,StrictModeTest,ProxyFlowTest}.php
tests/Feature/Admin/UpstreamCrudTest.php
tests/Unit/{ComposerProxyServiceTest,NpmProxyServiceTest}.php
```

---

### Task P1: Schema + Modelle (Upstream, Allowlist, Metadaten-Cache)

**Files:**
- Create: migration `create_upstreams_and_cache_tables`, `app/Enums/UpstreamPolicy.php`, `app/Models/{Upstream,UpstreamAllowedPackage}.php`, Factories
- Modify: `app/Models/Group.php` (upstreams() HasMany)
- Test: `tests/Feature/UpstreamSchemaTest.php`

- [ ] **Step 1: Failing test**

```php
<?php // tests/Feature/UpstreamSchemaTest.php
use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\Group;
use App\Models\Upstream;

it('attaches upstreams to a group with a policy and optional auth', function () {
    $group = Group::factory()->create();
    $up = Upstream::factory()->for($group)->create([
        'type' => PackageType::Composer,
        'url' => 'https://repo.packagist.org',
        'policy' => UpstreamPolicy::Proxy,
        'auth_token' => 'secret',
    ]);

    expect($group->upstreams()->first()->is($up))->toBeTrue()
        ->and($up->type)->toBe(PackageType::Composer)
        ->and($up->policy)->toBe(UpstreamPolicy::Proxy)
        ->and($up->auth_token)->toBe('secret');
});

it('records strict-mode allowlisted package names per upstream', function () {
    $up = Upstream::factory()->create(['policy' => UpstreamPolicy::Strict]);
    $up->allowedPackages()->create(['name' => 'symfony/console']);

    expect($up->allowedPackages()->pluck('name')->all())->toContain('symfony/console');
});
```

- [ ] **Step 2: FAIL**, **Step 3: Implement**

Enum `UpstreamPolicy: string { case Proxy = 'proxy'; case Strict = 'strict'; }`.

Migration:
```php
Schema::create('upstreams', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('group_id')->constrained()->cascadeOnDelete();
    $table->string('type');                 // PackageType composer|npm
    $table->string('url');                  // Upstream-Basis-URL
    $table->string('policy')->default('proxy');
    $table->text('auth_token')->nullable(); // optionale Bearer/Basic-Auth zum Upstream (verschlüsselt casten)
    $table->unsignedInteger('priority')->default(0);
    $table->boolean('enabled')->default(true);
    $table->timestamps();
});
Schema::create('upstream_allowed_packages', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('upstream_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->timestamps();
    $table->unique(['upstream_id', 'name']);
});
Schema::create('upstream_metadata_cache', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('upstream_id')->constrained()->cascadeOnDelete();
    $table->string('package_name');
    $table->jsonb('payload');               // rohe Upstream-Metadaten (vor URL-Rewrite)
    $table->timestamp('fetched_at');
    $table->timestamps();
    $table->unique(['upstream_id', 'package_name']);
});
```

Models (HasUuids, HasFactory, Larastan-Generics): `Upstream` (casts type=>PackageType, policy=>UpstreamPolicy, **auth_token => 'encrypted'**; relations group(), allowedPackages() HasMany), `UpstreamAllowedPackage` (upstream()). Factories mit sinnvollen Defaults. `Group::upstreams(): HasMany`.

- [ ] **Step 4:** `ddev php artisan migrate`, Suite grün, pint, phpstan.
- [ ] **Step 5: Commit** — `feat: upstream, allowlist and metadata-cache schema`

---

### Task P2: UpstreamClient + UpstreamCache

**Files:**
- Create: `app/Services/Upstream/UpstreamClient.php`, `app/Services/Upstream/UpstreamCache.php`
- Test: `tests/Unit/UpstreamClientTest.php`, `tests/Feature/UpstreamCacheTest.php`

`UpstreamClient` kapselt HTTP-Zugriffe (Laravel `Http`), setzt optionale Auth, wirft bei Fehlern eine `UpstreamException`. `UpstreamCache` verwaltet den DB-Metadaten-Cache (TTL, default 5 min via config `kontorfix.upstream_cache_ttl`) und den Artefakt-Cache auf der artifacts-Disk.

- [ ] **Step 1: Failing tests** (mit `Http::fake`)

```php
<?php // tests/Unit/UpstreamClientTest.php
use App\Models\Upstream;
use App\Services\Upstream\UpstreamClient;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('fetches upstream json and sends bearer auth when configured', function () {
    Http::fake(['repo.test/*' => Http::response(['ok' => true], 200)]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => 'tok']);

    $data = app(UpstreamClient::class)->getJson($up, '/p2/acme/demo.json');

    expect($data)->toBe(['ok' => true]);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok') && str_contains($r->url(), 'repo.test/p2/acme/demo.json'));
});

it('returns null on upstream 404', function () {
    Http::fake(['repo.test/*' => Http::response('', 404)]);
    $up = Upstream::factory()->create(['url' => 'https://repo.test', 'auth_token' => null]);

    expect(app(UpstreamClient::class)->getJson($up, '/p2/x/y.json'))->toBeNull();
});
```

```php
<?php // tests/Feature/UpstreamCacheTest.php
use App\Models\Upstream;
use App\Services\Upstream\UpstreamCache;

it('stores and returns cached metadata within ttl, misses after expiry', function () {
    config(['kontorfix.upstream_cache_ttl' => 300]);
    $up = Upstream::factory()->create();
    $cache = app(UpstreamCache::class);

    $cache->putMetadata($up, 'acme/demo', ['v' => 1]);
    expect($cache->getMetadata($up, 'acme/demo'))->toBe(['v' => 1]);

    // künstlich altern
    $up->metadataCache()->where('package_name', 'acme/demo')->update(['fetched_at' => now()->subHour()]);
    expect($cache->getMetadata($up, 'acme/demo'))->toBeNull();
});
```
(Model `Upstream::metadataCache(): HasMany` auf `UpstreamMetadataCache` — Model dafür anlegen, oder Query-Builder direkt. Simpel: kleines Model `UpstreamMetadataCache`.)

- [ ] **Step 2: FAIL**, **Step 3: Implement** — `UpstreamClient::getJson(Upstream, string $path): ?array` (200→array, 404→null, sonst UpstreamException), `getBytes(Upstream, string $absoluteUrl): ?string` (Tarball/Dist laden). `UpstreamCache::getMetadata/putMetadata` (TTL-geprüft), `artifactPath()/hasArtifact()/putArtifact()` auf artifacts-Disk unter `proxy/{upstream_id}/...`. Auth-Header aus `auth_token`. Timeouts + `->throw()` vermeiden (kontrolliert behandeln).

- [ ] **Step 4:** grün, pint, phpstan. **Step 5: Commit** — `feat: upstream http client and metadata/artifact cache`

---

### Task P3: ComposerProxyService + Composer-Fallthrough

**Files:**
- Create: `app/Services/Upstream/ComposerProxyService.php`
- Modify: `app/Http/Controllers/Registry/ComposerController.php` (metadata + root)
- Test: `tests/Unit/ComposerProxyServiceTest.php`, `tests/Feature/Registry/ComposerProxyTest.php`

Wenn `p2/{vendor}/{name}.json` lokal kein Paket findet UND die Gruppe einen aktiven composer-Upstream hat: Metadaten vom Upstream holen (via Cache), Dist-URLs auf `/r/{group}/proxy/composer/{upstream}/{package}/{version}` umschreiben, ausliefern. Strict-Mode: nur wenn das Paket in `allowedPackages` steht, sonst 404. Root-Endpoint: bei aktivem Upstream **kein** `available-packages` mehr ausgeben (nur `metadata-url`), damit der Composer-Client lazy jedes Paket anfragt.

- [ ] **Step 1: Failing tests** (`Http::fake` für den Upstream)

```php
<?php // tests/Feature/Registry/ComposerProxyTest.php
use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\{Group, Organization, Upstream};
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Support\Facades\Http;

function fakePackagistP2(string $package, string $version, string $distUrl): void
{
    Http::fake(["*/p2/{$package}.json" => Http::response([
        'minified' => 'composer/2.0',
        'packages' => [$package => [[
            'name' => $package, 'version' => $version, 'version_normalized' => $version.'.0',
            'dist' => ['type' => 'zip', 'url' => $distUrl, 'reference' => 'abc'],
        ]]],
    ], 200)]);
}

it('proxies composer metadata from the upstream and rewrites dist urls', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.packagist.org', 'policy' => UpstreamPolicy::Proxy]);
    fakePackagistP2('symfony/console', 'v6.0.0', 'https://api.github.com/repos/symfony/console/zipball/abc');

    $res = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/p2/symfony/console.json')->assertOk()->json();
    $v = MetadataMinifier::expand($res['packages']['symfony/console'])[0];

    expect($v['dist']['url'])->toStartWith('http://localhost/r/kadenz/proxy/composer/'.$up->id.'/symfony/console/');
});

it('serves 404 for a proxied package not on the strict allowlist', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.packagist.org', 'policy' => UpstreamPolicy::Strict]);
    fakePackagistP2('evil/pkg', 'v1.0.0', 'https://x/y.zip');

    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/p2/evil/pkg.json')->assertNotFound();

    $up->allowedPackages()->create(['name' => 'evil/pkg']);
    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/p2/evil/pkg.json')->assertOk();
});

it('omits available-packages on the root endpoint when an upstream is active', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Composer]);

    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/packages.json')
        ->assertOk()->assertJsonMissing(['available-packages'])->assertJsonPath('metadata-url', '/r/kadenz/p2/%package%.json');
});

it('prefers a local package over the upstream', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Composer]);
    $pkg = \App\Models\Package::factory()->create(['type' => PackageType::Composer, 'name' => 'local/pkg']);
    \App\Models\PackageVersion::factory()->for($pkg)->create();
    $group->packages()->attach($pkg);

    // lokaler Treffer -> kein Http-Aufruf zum Upstream
    Http::fake();
    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/p2/local/pkg.json')->assertOk();
    Http::assertNothingSent();
});
```

- [ ] **Step 2: FAIL**, **Step 3: Implement** — `ComposerController::metadata`: erst lokal (findAccessible ohne abort → try/catch oder eine „findLocal"-Variante), bei Miss `ComposerProxyService::metadata(Group, $upstream, $fullName)` → cached Upstream-Payload, Strict-Check, Dist-URL-Rewrite auf die Proxy-Route, `MetadataMinifier` egal (Upstream liefert schon minified; wir expandieren, rewriten, minifien neu ODER rewriten im minified-Format — sauberer: expand → rewrite dist.url je Version → minify). `root`: `available-packages` nur wenn KEIN aktiver Upstream. Proxy-Route-Pfad `/r/{group}/proxy/composer/{upstream}/{vendor}/{name}/{version}` (siehe P5).

- [ ] **Step 4:** grün, pint, phpstan. **Step 5: Commit** — `feat: composer upstream proxy with dist-url rewriting and strict mode`

---

### Task P4: NpmProxyService + npm-Fallthrough

**Files:**
- Create: `app/Services/Upstream/NpmProxyService.php`
- Modify: `app/Http/Controllers/Registry/NpmController.php` (packument)
- Test: `tests/Unit/NpmProxyServiceTest.php`, `tests/Feature/Registry/NpmProxyTest.php`

Analog zu P3 für npm: packument lokal nicht gefunden → Upstream (registry.npmjs.org) via Cache, `dist.tarball`-URLs jeder Version auf `/r/{group}/proxy/npm/{upstream}/{package}/-/{file}` umschreiben, Strict-Mode-Allowlist. `dist-tags` vom Upstream übernehmen.

- [ ] **Step 1: Failing tests** (`Http::fake` liefert ein npm-packument), analog zu ComposerProxyTest: rewrite tarball-URL, strict 404→allowlist→200, local-first (Http::assertNothingSent).
- [ ] **Step 2: FAIL**, **Step 3: Implement** `NpmProxyService::packument(Group, Upstream, $name)` → cached Upstream-packument, Strict-Check, tarball-URL-Rewrite je Version. `NpmController::respondPackument`: lokal (findLocal) → bei Miss Upstream.
- [ ] **Step 4:** grün, pint, phpstan. **Step 5: Commit** — `feat: npm upstream proxy with tarball-url rewriting and strict mode`

---

### Task P5: ProxyDownloadController (cache-on-first-hit)

**Files:**
- Create: `app/Http/Controllers/Registry/ProxyDownloadController.php`
- Modify: `routes/registry.php`
- Test: `tests/Feature/Registry/ProxyDownloadTest.php`

Die umgeschriebenen Dist-/Tarball-URLs zeigen hierher. Erster Hit: Artefakt vom Upstream laden (UpstreamClient::getBytes), auf artifacts-Disk cachen (atomar), streamen. Folge-Hits: aus dem Cache. Hinter `registry.auth` + ACL (canAccessGroup). Strict-Mode wird schon beim Metadaten-Rewrite erzwungen; hier zusätzlich prüfen, dass die Upstream-Gruppe passt.

- [ ] **Step 1: Failing tests** (`Http::fake` liefert Tarball-Bytes; `Storage::fake('artifacts')`):
  - erster GET lädt vom Upstream, cached auf Disk, streamt (richtiger Content-Type: zip für composer, octet-stream für npm);
  - zweiter GET liefert aus Cache ohne erneuten Http-Aufruf (`Http::assertSentCount(1)`);
  - 401 ohne Token, 404 bei fremder Gruppe.
- [ ] **Step 2: FAIL**, **Step 3: Implement** — Routen `/r/{group:slug}/proxy/composer/{upstream}/{vendor}/{name}/{version}` und `/r/{group:slug}/proxy/npm/{upstream}/{package}/-/{file}` (+ scoped). Der Controller löst den Upstream, prüft Gruppen-Zugehörigkeit + ACL, cached & streamt. Die Original-Upstream-URL kommt aus dem gecachten Metadaten-Payload (nicht aus der Client-Anfrage — kein SSRF über beliebige URLs).
- [ ] **Step 4:** grün, pint, phpstan. **Step 5: Commit** — `feat: proxy artifact download with cache-on-first-hit`

---

### Task P6: Admin-GUI — Upstreams pro Gruppe

**Files:**
- Create: `app/Http/Controllers/Admin/UpstreamController.php`, `app/Http/Requests/Admin/StoreUpstreamRequest.php`, Vue-Seite/Komponente
- Modify: `routes/web.php`, Gruppen-GUI (Upstreams je Gruppe verwalten)
- Test: `tests/Feature/Admin/UpstreamCrudTest.php`

- [ ] CRUD (index in der Gruppen-Detailansicht oder eigene Seite): Upstream anlegen (type, url `url:https`, policy, optional auth_token, priority), löschen, Allowlist-Einträge verwalten (bei strict). Role-gated wie die anderen Admin-Ressourcen. Tests: anlegen/validieren/löschen, member 403, auth_token wird verschlüsselt gespeichert & nie im Klartext im Index-Payload. Frontend: einfache Tabelle + Dialog im etablierten Stil; `ddev npm run build` + `ddev npm run lint` grün.
- [ ] **Commit** — `feat: upstream management gui per group`

---

### Task P7: E2E-Contract-Tests + realer Proxy-Smoke-Test

**Files:**
- Test: `tests/Feature/Registry/ProxyFlowTest.php`

- [ ] **Step 1:** Vollständiger Fake-Flow je Ökosystem: Upstream anlegen → `p2`/packument anfragen → Dist/Tarball über die Proxy-Route ziehen (cache-on-first) → zweiter Zug aus dem Cache. Plus Strict-Mode-Ende-zu-Ende.
- [ ] **Step 2: grün.**
- [ ] **Step 3: Realer Smoke-Test (dokumentieren)** gegen echte Upstreams (Host hat Netz):
  - Composer: Gruppe mit packagist-Upstream, `composer require psr/log` über `/r/<slug>` ziehen.
  - npm: Gruppe mit npmjs-Upstream, `npm install is-odd` über `/r/<slug>` ziehen.
  Ergebnis als Kommentarblock festhalten.
- [ ] **Step 4: Commit** — `test: end-to-end proxy flow for composer and npm`

---

## Self-Review (beim Schreiben)

1. **Spec-Coverage §5:** Proxy für packagist/npmjs (P3/P4) ✓, lokaler Cache von Metadaten (P2) + Artefakten (P5) ✓, Dist-Mirroring/cache-on-first (P5) ✓, Strict-Mode-Allowlist gegen Dependency Confusion (P3/P4/P6) ✓, eigener URL-Pfad pro Mirror (P5) ✓, Upstream-Auth serverseitig verborgen/verschlüsselt (P1/P2/P6) ✓. **Bewusst nicht in v0.3:** Full-Sync/Mass-Mirror, Metadata-Patching, Prefetch aller Zips, Cache-Purge-GUI — spätere Slices.
2. **Sicherheit:** Kein SSRF — Proxy-Download nutzt ausschließlich die im gecachten Upstream-Payload hinterlegten URLs, nie client-gelieferte; Upstream-Basis-URLs sind operator-konfiguriert (`url:https`). ACL vor jedem Proxy-Read/Download. local-first (kein Upstream-Call, wenn lokal vorhanden — verhindert Metadaten-Leak & Dependency-Confusion bei privaten Namen). Auth-Token `encrypted` gecastet, nie im Payload.
3. **Netzwerk in Tests:** ausschließlich `Http::fake`; realer Netzzugriff nur im dokumentierten manuellen Smoke-Test (P7).
4. **Typkonsistenz:** `UpstreamClient::getJson(Upstream,string):?array` / `getBytes(Upstream,string):?string`; `UpstreamCache::get/putMetadata`, `has/putArtifact`; Proxy-Routen-Pfade identisch zwischen Rewrite (P3/P4) und Controller (P5). `UpstreamPolicy::{Proxy,Strict}` überall.
5. **Composer available-packages:** bei aktivem Upstream entfällt es (P3), damit der Client lazy jedes Paket über metadata-url anfragt — sonst würde Composer annehmen, nur die gelisteten lokalen Pakete existierten.

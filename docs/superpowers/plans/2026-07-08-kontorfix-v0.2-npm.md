# Kontorfix v0.2 — npm-Modul Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** npm-Registry-Protokoll (install + publish) parallel zum Composer-Modul, group-scoped hinter derselben ACL-Schicht — sodass `npm install` und `npm publish` gegen `/r/{group}` funktionieren.

**Architecture:** Kein neues Abstraktions-Framework (YAGNI bei zwei Typen). npm-Endpoints leben als `NpmController` + `NpmMetadataBuilder` neben dem bestehenden `ComposerController`/`ComposerMetadataBuilder`, unter demselben `/r/{group:slug}`-Prefix und derselben `registry.auth`+`RegistryAccessService`-Kette. Geteilt: `Group`/`Package`/`PackageVersion`/`RegistryToken`, `RegistryAccessService`, artifacts-Disk. npm-Pakete werden per `npm publish` eingespielt (Tarball im Request), nicht per Git-Sync — `PackageVersion.source_reference` wird nullable, npm-Dist-Infos (shasum/integrity/tarball) kommen in dedizierte nullable Spalten. dist-tags als jsonb auf `packages`.

**Tech Stack:** wie v0.1 (Laravel 12, Pest/Postgres, Pint, Larastan level 6, Inertia/Vue). Keine neuen Composer-Pakete nötig (npm-Semver via bestehendem `composer/semver` reicht für `latest`-Ermittlung; Tarball-Handling mit PHP-Bordmitteln + `Storage`).

**Spec:** docs/superpowers/specs/2026-07-08-kontorfix-design.md §5 (npm-Modul).

**Konventionen:** wie v0.1 — Conventional Commits + Footer `Co-Authored-By: Claude <noreply@anthropic.com>`; `ddev php artisan test --compact`; `ddev exec vendor/bin/pint --dirty`; `ddev exec vendor/bin/phpstan --no-progress` (`[OK] No errors`); vor voller Suite `ddev exec 'rm -f /tmp/kfx-dist-* 2>/dev/null'`; nicht pushen (der Controller pusht am Ende gesammelt); `docs/`/`.claude/` nie anfassen; Larastan-Generics auf allen Relationen.

---

## Dateistruktur (neu/geändert)

```
database/migrations/<ts>_add_npm_fields_to_packages_and_versions.php  # dist_tags, nullable source_reference, dist_shasum/integrity/tarball_name
app/Models/Package.php            # dist_tags cast, npm-Helfer
app/Models/PackageVersion.php     # neue fillable/casts
app/Http/Middleware/AuthenticateRegistry.php  # + Bearer-Token (npm)
app/Services/Npm/NpmMetadataBuilder.php       # packument JSON
app/Services/Npm/NpmPublishService.php        # PUT-Body -> Version + Tarball
app/Http/Controllers/Registry/NpmController.php  # packument, tarball, publish, dist-tags
routes/registry.php               # npm-Routen (nach den Composer-Routen)
tests/Feature/Registry/{NpmAuthTest,NpmMetadataTest,NpmTarballTest,NpmPublishTest,NpmFlowTest}.php
tests/Unit/NpmMetadataBuilderTest.php
```

---

### Task N1: Schema — npm-Felder additiv

**Files:**
- Create: `database/migrations/<ts>_add_npm_fields_to_packages_and_versions.php` (`ddev php artisan make:migration add_npm_fields_to_packages_and_versions`)
- Modify: `app/Models/Package.php`, `app/Models/PackageVersion.php`
- Test: `tests/Feature/NpmSchemaTest.php`

- [ ] **Step 1: Failing test**

```php
<?php // tests/Feature/NpmSchemaTest.php
use App\Enums\PackageType;
use App\Models\Package;
use App\Models\PackageVersion;

it('stores npm dist-tags on a package and npm dist fields on a version', function () {
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => '@noixdev/ui-kit']);
    $pkg->update(['dist_tags' => ['latest' => '1.2.0']]);

    $v = PackageVersion::factory()->for($pkg)->create([
        'version' => '1.2.0',
        'version_pretty' => '1.2.0',
        'source_reference' => null,               // npm hat keinen Git-Commit
        'dist_shasum' => str_repeat('a', 40),
        'dist_integrity' => 'sha512-'.base64_encode(str_repeat('x', 64)),
        'dist_tarball_name' => 'ui-kit-1.2.0.tgz',
    ]);

    expect($pkg->fresh()->dist_tags)->toBe(['latest' => '1.2.0'])
        ->and($v->source_reference)->toBeNull()
        ->and($v->dist_shasum)->toHaveLength(40);
});
```

- [ ] **Step 2: Run → FAIL** (`Undefined column dist_tags`)
`ddev php artisan test --compact --filter=NpmSchemaTest`

- [ ] **Step 3: Migration**

```php
public function up(): void
{
    Schema::table('packages', function (Blueprint $table) {
        $table->jsonb('dist_tags')->nullable();   // npm: {"latest":"1.2.0",...}
    });
    Schema::table('package_versions', function (Blueprint $table) {
        $table->string('source_reference')->nullable()->change();  // npm hat keinen Commit
        $table->string('dist_shasum')->nullable();       // npm sha1
        $table->string('dist_integrity')->nullable();    // npm SRI sha512
        $table->string('dist_tarball_name')->nullable(); // Dateiname des Tarballs
    });
}

public function down(): void
{
    Schema::table('packages', fn (Blueprint $t) => $t->dropColumn('dist_tags'));
    Schema::table('package_versions', function (Blueprint $table) {
        $table->dropColumn(['dist_shasum', 'dist_integrity', 'dist_tarball_name']);
        // source_reference bleibt nullable — kein destruktives Zurückändern
    });
}
```
NOTE: `->change()` auf source_reference braucht `doctrine/dbal`? In Laravel 11+/12 ist `change()` nativ ohne dbal. Falls die Migration meckert, `ddev composer require doctrine/dbal --dev` ist NICHT nötig — Laravel 12 kann das nativ. Falls doch Fehler: die Spalte war NOT NULL; ein additiver Ansatz ohne change() wäre, sie nullable neu anzulegen — aber `change()` ist sauberer, zuerst so versuchen.

- [ ] **Step 4: Models**

`Package`: `dist_tags` in `$fillable`, cast `'dist_tags' => 'array'`. `PackageVersion`: add `dist_shasum`, `dist_integrity`, `dist_tarball_name` to `$fillable` (source_reference schon fillable). Keine neuen Casts nötig (alles string).

- [ ] **Step 5:** `ddev php artisan migrate` → `ddev php artisan test --compact` grün, pint, phpstan `[OK]`.

- [ ] **Step 6: Commit** — `feat: npm schema — dist-tags, nullable source ref, tarball dist fields`

---

### Task N2: Bearer-Token-Auth für npm (Middleware erweitern)

**Files:**
- Modify: `app/Http/Middleware/AuthenticateRegistry.php`
- Test: `tests/Feature/Registry/NpmAuthTest.php`

npm-Clients senden `Authorization: Bearer <token>`. Composer sendet HTTP Basic. Die Middleware muss beides auflösen, ohne Composer zu brechen.

- [ ] **Step 1: Failing test**

```php
<?php // tests/Feature/Registry/NpmAuthTest.php
use App\Models\Organization;
use App\Models\RegistryToken;
use Illuminate\Support\Facades\Route;

it('resolves a bearer token onto the request (npm style)', function () {
    Route::get('/_test/reg', fn (\Illuminate\Http\Request $r) => response()->json([
        'id' => $r->attributes->get('registryToken')?->id,
    ]))->middleware('registry.auth');

    $org = Organization::factory()->create();
    [$token, $plain] = RegistryToken::issue($org, 'npm', null);

    $this->withHeaders(['Authorization' => 'Bearer '.$plain])
        ->getJson('/_test/reg')->assertOk()->assertJsonPath('id', $token->id);
});

it('still resolves basic auth (composer style) and stays anonymous without creds', function () {
    Route::get('/_test/reg', fn (\Illuminate\Http\Request $r) => response()->json([
        'id' => $r->attributes->get('registryToken')?->id,
    ]))->middleware('registry.auth');

    $org = Organization::factory()->create();
    [$token, $plain] = RegistryToken::issue($org, 'composer', null);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('token:'.$plain)])
        ->getJson('/_test/reg')->assertOk()->assertJsonPath('id', $token->id);
    $this->getJson('/_test/reg')->assertOk()->assertJsonPath('id', null);
});
```

- [ ] **Step 2: Run → FAIL** (Bearer nicht aufgelöst → id null)

- [ ] **Step 3: Implement** — in `AuthenticateRegistry::handle`, vor der Basic-Logik den Bearer-Header prüfen:

```php
public function handle(Request $request, Closure $next): Response
{
    $candidate = $request->bearerToken()   // npm: Authorization: Bearer <token>
        ?: ($request->getPassword() ?: $request->getUser());  // composer: HTTP Basic

    $token = $candidate ? RegistryToken::findByPlainText($candidate) : null;

    if ($token && ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute()))) {
        $token->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    $request->attributes->set('registryToken', $token);

    return $next($request);
}
```

- [ ] **Step 4:** volle Suite grün (die bestehenden RegistryAuthTest-Fälle müssen weiter grün sein), pint, phpstan.

- [ ] **Step 5: Commit** — `feat: accept bearer tokens for npm clients in registry auth`

---

### Task N3: NpmMetadataBuilder (packument)

**Files:**
- Create: `app/Services/Npm/NpmMetadataBuilder.php`
- Test: `tests/Unit/NpmMetadataBuilderTest.php`

Das npm-„packument" ist ein JSON-Dokument: `name`, `dist-tags` (`{latest: "1.2.0"}`), `versions` (map version→manifest mit `dist.tarball`/`dist.shasum`/`dist.integrity`). Der Manifest-Inhalt jeder Version ist die gespeicherte `metadata` (das package.json der Version) plus die von uns gesetzten `dist`-Felder.

- [ ] **Step 1: Failing test**

```php
<?php // tests/Unit/NpmMetadataBuilderTest.php
use App\Enums\PackageType;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\Npm\NpmMetadataBuilder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('builds a packument with dist-tags and tarball urls scoped to the group', function () {
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => '@noixdev/ui-kit', 'dist_tags' => ['latest' => '1.2.0']]);
    PackageVersion::factory()->for($pkg)->create([
        'version' => '1.2.0', 'version_pretty' => '1.2.0',
        'metadata' => ['name' => '@noixdev/ui-kit', 'version' => '1.2.0', 'dependencies' => []],
        'dist_shasum' => str_repeat('a', 40),
        'dist_integrity' => 'sha512-abc',
        'dist_tarball_name' => 'ui-kit-1.2.0.tgz',
    ]);

    $doc = app(NpmMetadataBuilder::class)->build($pkg, 'kadenz', 'https://registry.test');

    expect($doc['name'])->toBe('@noixdev/ui-kit')
        ->and($doc['dist-tags'])->toBe(['latest' => '1.2.0'])
        ->and($doc['versions']['1.2.0']['dist']['tarball'])
            ->toBe('https://registry.test/r/kadenz/@noixdev/ui-kit/-/ui-kit-1.2.0.tgz')
        ->and($doc['versions']['1.2.0']['dist']['shasum'])->toBe(str_repeat('a', 40))
        ->and($doc['versions']['1.2.0']['dist']['integrity'])->toBe('sha512-abc');
});

it('derives latest from highest semver when dist_tags is empty', function () {
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'thing', 'dist_tags' => null]);
    foreach (['1.0.0', '2.1.0', '2.0.0'] as $v) {
        PackageVersion::factory()->for($pkg)->create(['version' => $v, 'version_pretty' => $v, 'metadata' => ['name' => 'thing', 'version' => $v], 'dist_tarball_name' => "thing-$v.tgz"]);
    }
    $doc = app(NpmMetadataBuilder::class)->build($pkg, 'kadenz', 'https://registry.test');
    expect($doc['dist-tags']['latest'])->toBe('2.1.0');
});
```

- [ ] **Step 2: FAIL**, **Step 3: Implement**

```php
<?php

namespace App\Services\Npm;

use App\Models\Package;
use Composer\Semver\Semver;

class NpmMetadataBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Package $package, string $groupSlug, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $versions = [];

        foreach ($package->versions()->get() as $v) {
            $manifest = $v->metadata ?? [];
            $manifest['name'] = $package->name;
            $manifest['version'] = $v->version;
            $manifest['dist'] = array_filter([
                'tarball' => "{$baseUrl}/r/{$groupSlug}/{$package->name}/-/{$v->dist_tarball_name}",
                'shasum' => $v->dist_shasum,
                'integrity' => $v->dist_integrity,
            ], fn ($x) => $x !== null);
            $versions[$v->version] = $manifest;
        }

        return [
            'name' => $package->name,
            'dist-tags' => $this->distTags($package, array_keys($versions)),
            'versions' => $versions,
        ];
    }

    /**
     * @param  list<string>  $versionList
     * @return array<string, string>
     */
    private function distTags(Package $package, array $versionList): array
    {
        $tags = $package->dist_tags ?? [];
        if (! isset($tags['latest']) && $versionList !== []) {
            $sorted = Semver::rsort($versionList);
            $tags['latest'] = $sorted[0];
        }

        return $tags;
    }
}
```
PHPStan: return-Shapes annotieren wie oben; `$manifest` als `array<string,mixed>`.

- [ ] **Step 4: grün + pint + phpstan.** **Step 5: Commit** — `feat: npm packument builder with dist-tags and tarball urls`

---

### Task N4: npm-Read-Endpoints — packument + tarball (mit Routing-Sorgfalt)

**Files:**
- Create: `app/Http/Controllers/Registry/NpmController.php`
- Modify: `routes/registry.php` (npm-Routen NACH den Composer-Routen)
- Test: `tests/Feature/Registry/NpmMetadataTest.php`, `tests/Feature/Registry/NpmTarballTest.php`

**Routing-Kollision:** Unter `/r/{group:slug}` liegen bereits `packages.json`, `p2/...`, `dists/...` (Composer). npm braucht `GET /{package}` und `GET /{package}/-/{tarball}`, wobei `{package}` auch `@scope/name` (mit Slash) sein kann. Lösung: Composer-Routen bleiben zuerst registriert (First-Match). npm-Routen mit expliziten Mustern für scoped/unscoped, und der packument-Catch-all schließt reservierte Composer-Pfade per Constraint aus.

- [ ] **Step 1: Failing test**

```php
<?php // tests/Feature/Registry/NpmMetadataTest.php
use App\Enums\PackageType;
use App\Models\{Group, Organization, Package, PackageVersion};

it('serves an npm packument for an assigned scoped package', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => '@noixdev/ui-kit', 'dist_tags' => ['latest' => '1.0.0']]);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => ['name' => '@noixdev/ui-kit', 'version' => '1.0.0'], 'dist_tarball_name' => 'ui-kit-1.0.0.tgz']);
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))
        ->getJson('/r/kadenz/@noixdev/ui-kit')
        ->assertOk()
        ->assertJsonPath('name', '@noixdev/ui-kit')
        ->assertJsonPath('dist-tags.latest', '1.0.0')
        ->assertJsonPath('versions.1\.0\.0.dist.tarball', 'http://localhost/r/kadenz/@noixdev/ui-kit/-/ui-kit-1.0.0.tgz');
});

it('serves an unscoped packument', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => ['name' => 'leftpad'], 'dist_tarball_name' => 'leftpad-1.0.0.tgz']);
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/leftpad')->assertOk()->assertJsonPath('name', 'leftpad');
});

it('401 without token, 404 for unassigned npm package', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $secret = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'secret']);
    PackageVersion::factory()->for($secret)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'x.tgz']);

    $this->getJson('/r/kadenz/leftpad')->assertUnauthorized();
    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/secret')->assertNotFound();
});

it('does not shadow the composer root route', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/packages.json')->assertOk()
        ->assertJsonPath('metadata-url', '/r/kadenz/p2/%package%.json');
});
```
(`tokenHeaderFor` liegt in `tests/Pest.php`.)

```php
<?php // tests/Feature/Registry/NpmTarballTest.php
use App\Enums\PackageType;
use App\Models\{Group, Organization, Package, PackageVersion};
use Illuminate\Support\Facades\Storage;

it('streams a stored npm tarball with the right content type', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $v = PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'leftpad-1.0.0.tgz', 'dist_path' => "tarballs/{$pkg->id}/leftpad-1.0.0.tgz"]);
    Storage::disk('artifacts')->put($v->dist_path, 'tarball-bytes');
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))->get('/r/kadenz/leftpad/-/leftpad-1.0.0.tgz')
        ->assertOk()->assertHeader('content-type', 'application/octet-stream');
});

it('denies tarball download without package access', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'leftpad-1.0.0.tgz', 'dist_path' => 'x']);
    // nicht zugewiesen
    $this->withHeaders(tokenHeaderFor($group))->get('/r/kadenz/leftpad/-/leftpad-1.0.0.tgz')->assertNotFound();
});
```

- [ ] **Step 2: FAIL**, **Step 3: Implement**

`NpmController` (Konstruktor injiziert `RegistryAccessService` + `NpmMetadataBuilder`; nutzt dieselben ACL-Helfer wie ComposerController — `authorizeGroup`/`findAccessible`, hier für type=npm). packument:

```php
public function packument(Request $request, Group $group, string $package): JsonResponse
{
    $this->authorizeGroup($request, $group);
    $pkg = $this->findAccessibleNpm($request, $group, $package);

    return response()->json($this->metadata->build($pkg, $group->slug, $request->getSchemeAndHttpHost()));
}

public function tarball(Request $request, Group $group, string $package, string $file)
{
    $this->authorizeGroup($request, $group);
    $pkg = $this->findAccessibleNpm($request, $group, $package);
    $version = $pkg->versions()->where('dist_tarball_name', $file)->firstOrFail();

    $disk = Storage::disk('artifacts');
    abort_unless($version->dist_path && $disk->exists($version->dist_path), 404);

    return response()->streamDownload(function () use ($disk, $version) {
        $stream = $disk->readStream($version->dist_path);
        if ($stream !== null) { fpassthru($stream); fclose($stream); }
    }, $file, ['Content-Type' => 'application/octet-stream']);
}
```
`findAccessibleNpm` = wie `findAccessible`, aber `where('type', 'npm')`. Um Duplizierung zu vermeiden: die ACL-Helfer aus ComposerController in einen gemeinsamen Trait `ResolvesRegistryPackage` (in `app/Http/Controllers/Registry/`) auslegen, den beide Controller `use`n; `findAccessible` bekommt einen `PackageType`-Parameter. (Refactor: ComposerController auf den Trait umstellen, Tests müssen grün bleiben.)

`routes/registry.php` — npm NACH Composer, scoped + unscoped, Tarball vor packument (spezifischer zuerst):
```php
// npm — nach den Composer-Routen. Tarball-Routen (spezifischer) zuerst.
Route::get('/{scope}/{package}/-/{file}', [NpmController::class, 'tarball'])
    ->where(['scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._-]+\.tgz']);
Route::get('/{package}/-/{file}', [NpmController::class, 'tarball'])
    ->where(['package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._-]+\.tgz']);
Route::get('/{scope}/{package}', [NpmController::class, 'packumentScoped'])
    ->where(['scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+']);
Route::get('/{package}', [NpmController::class, 'packument'])
    ->where(['package' => '(?!packages\.json)[a-z0-9._-]+']);
```
Für scoped: `packumentScoped(Request, Group, string $scope, string $package)` reicht `"$scope/$package"` an die gemeinsame Logik. Die negative Lookahead-Constraint `(?!packages\.json)` schützt die Composer-Root zusätzlich zur Registrierungsreihenfolge. `p2`/`dists` kollidieren nicht, da sie mehr Segmente haben und zuerst registriert sind.

- [ ] **Step 4: grün** (inkl. „does not shadow composer" + volle Suite), pint, phpstan.
- [ ] **Step 5: Commit** — `feat: npm packument and tarball endpoints with shared acl`

---

### Task N5: npm publish (PUT) + NpmPublishService

**Files:**
- Create: `app/Services/Npm/NpmPublishService.php`
- Modify: `app/Http/Controllers/Registry/NpmController.php` (publish-Action), `routes/registry.php` (PUT-Routen)
- Test: `tests/Feature/Registry/NpmPublishTest.php`

`npm publish` sendet `PUT /r/{group}/{package}` mit JSON-Body: `{ name, versions: {<v>: manifest}, dist-tags: {latest: <v>}, _attachments: { "<file>.tgz": { data: <base64>, length } } }`. Nur Maintainer/Admin dürfen publishen (das Token braucht `ability=publish` ODER der Request kommt von einem eingeloggten Maintainer — hier: **Token mit `ability=publish`**). Der Service dekodiert das Attachment, speichert den Tarball auf der artifacts-Disk unter `tarballs/{package_id}/{file}`, berechnet shasum (sha1) + integrity (sha512 base64, `sha512-...`), legt die `PackageVersion` an und mergt `dist-tags`.

- [ ] **Step 1: Failing test**

```php
<?php // tests/Feature/Registry/NpmPublishTest.php
use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\{Group, Organization, Package, RegistryToken};
use Illuminate\Support\Facades\Storage;

function publishBody(string $name, string $version, string $file, string $bytes): array
{
    return [
        'name' => $name,
        'versions' => [$version => ['name' => $name, 'version' => $version, 'dependencies' => []]],
        'dist-tags' => ['latest' => $version],
        '_attachments' => [$file => ['content_type' => 'application/octet-stream', 'data' => base64_encode($bytes), 'length' => strlen($bytes)]],
    ];
}

function publishHeaderFor(Group $group): array
{
    [, $plain] = RegistryToken::issue($group->organization, 'ci', $group, TokenAbility::Publish);
    return ['Authorization' => 'Bearer '.$plain];
}

it('publishes an npm version, stores the tarball and computes integrity', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);
    $bytes = 'fake-tarball-bytes';

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', $bytes))
        ->assertOk();

    $v = $pkg->fresh()->versions()->where('version', '1.0.0')->firstOrFail();
    expect($v->dist_shasum)->toBe(sha1($bytes))
        ->and($v->dist_integrity)->toStartWith('sha512-')
        ->and($pkg->fresh()->dist_tags['latest'])->toBe('1.0.0');
    Storage::disk('artifacts')->assertExists("tarballs/{$pkg->id}/leftpad-1.0.0.tgz");
});

it('rejects publish without a publish-ability token', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);
    [, $plain] = RegistryToken::issue($group->organization, 'read', $group, TokenAbility::Read);

    $this->withHeaders(['Authorization' => 'Bearer '.$plain])
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', 'x'))
        ->assertForbidden();
});

it('is idempotent-safe: republishing the same version is rejected', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);
    $body = publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', 'x');

    $this->withHeaders(publishHeaderFor($group))->putJson('/r/kadenz/leftpad', $body)->assertOk();
    $this->withHeaders(publishHeaderFor($group))->putJson('/r/kadenz/leftpad', $body)->assertStatus(409);
});
```

- [ ] **Step 2: FAIL**, **Step 3: Implement**

`NpmPublishService::publish(Package $package, array $body): PackageVersion` — validiert genau eine neue Version, dekodiert `_attachments`, wirft bei Doppel-Version eine `VersionConflictException` (→ 409), speichert Tarball, berechnet `sha1`/`sha512`, `updateOrCreate` verboten (Konflikt gewollt), setzt `dist_tags`. Der Controller:
```php
public function publish(Request $request, Group $group, string $package) { /* scoped-Variante analog */
    $this->authorizeGroup($request, $group);
    $token = $request->attributes->get('registryToken');
    abort_unless($token && $token->ability === TokenAbility::Publish, 403);
    $pkg = $this->findAccessibleNpm($request, $group, $package);
    try {
        $this->publisher->publish($pkg, $request->json()->all());
    } catch (VersionConflictException) {
        abort(409, 'Version already exists.');
    }
    return response()->json(['ok' => true]);
}
```
integrity: `'sha512-'.base64_encode(hash('sha512', $bytes, true))`. shasum: `sha1($bytes)`. Tarball-Pfad `tarballs/{$package->id}/{$file}`, atomar schreiben (staging + move wie beim Composer-Dist). PUT-Routen in `routes/registry.php` (scoped + unscoped) mit `->where` wie bei packument.

**Sicherheit (aus v0.1-Reviews):** Dateiname aus `_attachments`-Key gegen `^[a-z0-9._-]+\.tgz$` validieren (kein Pfad-Traversal in den Storage-Key). Nur EINE Version pro publish akzeptieren; `name` im Body muss zum Paket passen.

- [ ] **Step 4: grün + pint + phpstan.** **Step 5: Commit** — `feat: npm publish with tarball storage, integrity and version-conflict handling`

---

### Task N6: E2E-Contract-Test + realer npm-Smoke-Test

**Files:**
- Test: `tests/Feature/Registry/NpmFlowTest.php`

- [ ] **Step 1: Test** — publish → packument → tarball in einem Durchlauf (HTTP-Ebene), plus 401-ohne-Token quer über die Endpoints:

```php
<?php // tests/Feature/Registry/NpmFlowTest.php
use App\Enums\PackageType;
use App\Models\{Group, Organization, Package};
use Illuminate\Support\Facades\Storage;

it('completes publish -> packument -> tarball', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);
    $bytes = 'hello-tarball';

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', $bytes))->assertOk();

    $doc = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/leftpad')->assertOk()->json();
    $tarballUrl = $doc['versions']['1.0.0']['dist']['tarball'];
    $path = parse_url($tarballUrl, PHP_URL_PATH);

    $this->withHeaders(tokenHeaderFor($group))->get($path)->assertOk()
        ->assertHeader('content-type', 'application/octet-stream');
});
```
(`publishBody`/`publishHeaderFor` aus NpmPublishTest — nach `tests/Pest.php` heben, damit beide Dateien sie teilen.)

- [ ] **Step 2: grün.**

- [ ] **Step 3: Manueller Smoke-Test (dokumentieren, wie beim Composer-Flow)** — gegen den DDEV-Server ein Paket per `npm publish` einspielen und per `npm install` in einem Wegwerf-Projekt ziehen:
```
# .npmrc im Testprojekt:
@noixdev:registry=https://kontorfix.ddev.site/r/smoke
//kontorfix.ddev.site/r/smoke/:_authToken=kfx_...
# npm publish (mit publish-Token), dann npm install @noixdev/… in einem zweiten Projekt.
```
Ergebnis als Kommentarblock in `NpmFlowTest.php` festhalten.

- [ ] **Step 4: Commit** — `test: end-to-end npm publish and install contract flow`

---

## Self-Review (beim Schreiben)

1. **Spec-Coverage (§5 npm):** packument-Metadaten (N3/N4) ✓, Tarball-Streaming (N4) ✓, PUT publish (N5) ✓, dist-tags (N3/N5) ✓, Token-Auth npm-Style Bearer (N2) ✓, ACL geteilt (N4) ✓. **Bewusst nicht in v0.2:** dist-tag-Verwaltungs-Endpoints (`npm dist-tag add`), unpublish/deprecate, npm-Search, GUI-Anzeige von npm-Setup-Snippets — Folge-Slice.
2. **Kollisionen:** npm-`{package}`-Catch-all vs. Composer-`packages.json` durch Registrierungsreihenfolge + negative Lookahead abgesichert (N4, mit explizitem Shadowing-Test).
3. **Typkonsistenz:** `NpmMetadataBuilder::build(Package, string $groupSlug, string $baseUrl)` einheitlich in N3/N4. `dist_tarball_name`/`dist_shasum`/`dist_integrity`/`dist_path` (Storage-Pfad) konsistent über N1/N4/N5. `TokenAbility::Publish`-Gate in N5. `findAccessibleNpm` = ACL-Helfer mit `PackageType::Npm`.
4. **Sicherheit (v0.1-Lektionen angewandt):** Tarball-Dateiname gegen Regex validiert (kein Traversal), atomare Writes, ACL VOR jeder Auslieferung/Publish, 401/404-Semantik ohne Existenz-Leak, publish nur mit `ability=publish`.

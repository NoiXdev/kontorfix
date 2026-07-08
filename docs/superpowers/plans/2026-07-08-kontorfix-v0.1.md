# Kontorfix v0.1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kontorfix v0.1 — private Composer-Registry mit globalem Paket-Pool, Gruppen (Slug-Routing), revozierbaren Tokens und Admin-GUI (Inertia/Vue) inkl. flüssiger Paket-Zuweisung.

**Architecture:** Eine Laravel-App (UUID-v7-Schlüssel überall, PostgreSQL als einzige Wahrheit) mit klar getrennten Schichten: VCS-Service (git), Sync-Jobs (Horizon-fähige Queue), ein zentraler `RegistryAccessService` vor **jeder** Metadaten-/Dist-Auslieferung, Composer-Protokoll-Controller (packages.json / p2 / dists), Inertia-Admin-GUI. Storage ausschließlich über Flysystem-Disk `artifacts`.

**Tech Stack:** Laravel (aktuelle Major, Vue-Starter-Kit), PHP 8.4, Inertia v2 + Vue 3 + Tailwind 4, PostgreSQL 17, Redis, Pest, Pint, Larastan, composer/semver, composer/metadata-minifier, DDEV (Projekt `kontorfix`).

**Spec:** `docs/superpowers/specs/2026-07-08-kontorfix-design.md` — v0.1-Scope aus Abschnitt 14: Kern-Datenmodell, Composer-Modul (private Pakete), Admin-GUI-Basis, Tokens, Slug-Routing (Multi-Domain kommt in v0.2, die `domains`-Tabelle wird aber schon angelegt).

**Konventionen für alle Tasks:**
- Conventional Commits, Footer `Co-Authored-By: Claude <noreply@anthropic.com>`.
- Tests laufen mit `ddev php artisan test --compact` (bzw. `php artisan test` wenn ohne DDEV ausgeführt).
- Jede Migration nutzt UUIDs: `$table->uuid('id')->primary();` + `foreignUuid(...)`.
- Neue Models nutzen `HasUuids` (erzeugt in aktuellen Laravel-Versionen UUID v7) + `HasFactory`.
- Keine destruktiven Migrationen.

---

## Dateistruktur (Zielbild v0.1)

```
app/
  Enums/PackageType.php, SyncStatus.php, UserRole.php, TokenAbility.php
  Models/Organization.php, User.php, Package.php, PackageVersion.php,
         Group.php, Domain.php, RegistryToken.php
  Services/Vcs/GitRepository.php            # clone/fetch, Tags, Datei@Ref, Archiv
  Services/Composer/ComposerMetadataBuilder.php
  Services/RegistryAccessService.php        # DIE eine ACL-Schicht
  Jobs/SyncPackage.php
  Http/Middleware/AuthenticateRegistry.php  # HTTP Basic -> RegistryToken
  Http/Controllers/Registry/ComposerController.php   # packages.json, p2, dist
  Http/Controllers/Admin/{PackageController,GroupController,TokenController,PackageSearchController}.php
  Http/Requests/Admin/{StorePackageRequest,StoreGroupRequest,StoreTokenRequest}.php
routes/registry.php                          # /r/{group}/... Endpoints
resources/js/pages/admin/packages/Index.vue
resources/js/pages/admin/groups/Index.vue    # inkl. CreateGroupSheet.vue
resources/js/pages/admin/tokens/Index.vue
resources/js/components/kontorfix/{GroupSheet.vue,PackagePicker.vue,TypeBadge.vue,StatusPill.vue}
tests/Feature/Registry/{ComposerMetadataTest,ComposerDistTest,RegistryAuthTest,AclMatrixTest,ComposerFlowTest}.php
tests/Feature/Admin/{PackageCrudTest,GroupCrudTest,TokenCrudTest,PackageSearchTest}.php
tests/Unit/{ComposerMetadataBuilderTest,GitRepositoryTest}.php
docker/{Dockerfile,compose.yaml,entrypoint.sh}
.github/workflows/ci.yml
```

---

### Task 1: Laravel-Skeleton, DDEV, Tooling

**Files:**
- Create: komplettes Laravel-Vue-Starter-Kit im Repo-Root (docs/ bleibt unberührt)
- Create: `.ddev/config.yaml` (via `ddev config`), `phpstan.neon`, `pint.json`

- [ ] **Step 1: Skeleton in Temp-Verzeichnis erzeugen und in Repo-Root mergen**

```bash
cd /Users/noidee/_dev/code-registry
composer create-project laravel/vue-starter-kit /tmp/kfx-skeleton --no-interaction
rsync -a --exclude .git /tmp/kfx-skeleton/ ./
rm -rf /tmp/kfx-skeleton
git add -A && git status --short | head -30
```
Falls `laravel/vue-starter-kit` nicht existiert (Versionswechsel): `laravel new /tmp/kfx-skeleton --vue --pest --no-interaction` verwenden. Prüfen: `php artisan --version` (aktuelle Major), `ls resources/js/pages`.

- [ ] **Step 2: DDEV konfigurieren (Postgres + Redis)**

```bash
ddev config --project-name=kontorfix --project-type=laravel --docroot=public --php-version=8.4 --database=postgres:17
ddev add-on get ddev/ddev-redis
ddev start && ddev composer install && ddev npm install
```
`.env` anpassen: `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_HOST=redis`. Prüfen: `ddev php artisan migrate` läuft durch.

- [ ] **Step 3: Tooling installieren**

```bash
ddev composer require --dev larastan/larastan laravel/pint
ddev composer require composer/semver composer/metadata-minifier
```

`phpstan.neon`:
```neon
includes:
    - vendor/larastan/larastan/extension.neon
parameters:
    paths: [app]
    level: 6
```

- [ ] **Step 4: Artifacts-Disk registrieren**

In `config/filesystems.php` unter `disks`:
```php
'artifacts' => [
    'driver' => 'local',
    'root' => storage_path('app/artifacts'),
    'serve' => false,
    'throw' => true,
],
```
(S3-Variante kommt in v0.4 per GUI-Konfiguration — Zugriff im Code ausschließlich über `Storage::disk('artifacts')`.)

- [ ] **Step 5: Verifizieren + Commit**

```bash
ddev php artisan test --compact   # Starter-Kit-Tests grün
ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan
git add -A && git commit -m "feat: scaffold laravel vue starter kit with ddev, pint, larastan, pest"
```

---

### Task 2: UUID-Basis + Organization/Role am User

**Files:**
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php` (frisches Projekt — Umbau erlaubt, noch nirgends deployed)
- Create: `database/migrations/<ts>_create_organizations_table.php`
- Create: `app/Models/Organization.php`, `app/Enums/UserRole.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`
- Test: `tests/Feature/UuidModelsTest.php`

- [ ] **Step 1: Failing Test schreiben**

```php
<?php // tests/Feature/UuidModelsTest.php
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

it('creates users and organizations with uuid v7 keys', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->for($org)->create(['role' => UserRole::Admin]);

    expect(Str::isUuid($org->id))->toBeTrue()
        ->and(Str::isUuid($user->id))->toBeTrue()
        ->and($user->organization->is($org))->toBeTrue()
        ->and($user->role)->toBe(UserRole::Admin);
});
```

- [ ] **Step 2: Test ausführen — erwartet FAIL** (`Class App\Models\Organization not found`)

```bash
ddev php artisan test --compact --filter=UuidModelsTest
```

- [ ] **Step 3: Implementieren**

`app/Enums/UserRole.php`:
```php
<?php
namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Maintainer = 'maintainer';
    case Member = 'member';
}
```

Migration `create_organizations_table.php`:
```php
Schema::create('organizations', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('slug')->unique();
    $table->boolean('is_operator')->default(false); // Betreiber-Org
    $table->timestamps();
});
```

In `0001_..._create_users_table.php` die id-Zeile ersetzen und Spalten ergänzen:
```php
$table->uuid('id')->primary();
// ... bestehende Spalten behalten, zusätzlich:
$table->foreignUuid('organization_id')->nullable()->constrained();
$table->string('role')->default('member');
```
(Auch `sessions.user_id` in derselben Migration auf `$table->foreignUuid('user_id')->nullable()->index();` ändern.)

`app/Models/Organization.php`:
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'slug', 'is_operator'];
    protected function casts(): array { return ['is_operator' => 'bool']; }

    public function users(): HasMany { return $this->hasMany(User::class); }
}
```

`User.php`: `use HasUuids;` ergänzen, `organization_id`/`role` in `$fillable`, Cast `'role' => UserRole::class`, Relation `organization(): BelongsTo`. `UserFactory`: `'role' => UserRole::Member`, `'organization_id' => Organization::factory()`.

`OrganizationFactory`:
```php
public function definition(): array
{
    return ['name' => $n = fake()->company(), 'slug' => Str::slug($n).'-'.fake()->unique()->numberBetween(1, 9999)];
}
```

- [ ] **Step 4: Tests grün + gesamte Suite**

```bash
ddev php artisan migrate:fresh && ddev php artisan test --compact
```
Erwartung: PASS (auch Starter-Kit-Auth-Tests — falls dort Factories `for(Organization...)` fehlen, Factory-Default deckt das ab).

- [ ] **Step 5: Commit** — `feat: uuid v7 keys, organizations and user roles`

---

### Task 3: Kern-Schema — Packages, Versions, Groups, Domains, Tokens

**Files:**
- Create: `app/Enums/{PackageType,SyncStatus,TokenAbility}.php`
- Create: Migrationen + Models + Factories für `Package`, `PackageVersion`, `Group`, `Domain`, `RegistryToken` + Pivot `group_package`
- Test: `tests/Feature/CoreSchemaTest.php`

- [ ] **Step 1: Failing Test**

```php
<?php // tests/Feature/CoreSchemaTest.php
use App\Enums\PackageType;
use App\Models\{Group, Organization, Package, PackageVersion};

it('assigns pool packages to groups with constraints', function () {
    $pkg = Package::factory()->create(['name' => 'kadenz/shop-bridge']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.2.0']);
    $group = Group::factory()->create(['slug' => 'kadenz']);

    $group->packages()->attach($pkg, ['available_until' => now()->addYear()]);

    expect($group->packages()->first()->is($pkg))->toBeTrue()
        ->and($pkg->type)->toBe(PackageType::Composer)
        ->and($group->packages()->first()->pivot->available_until)->not->toBeNull();
});

it('links groups to an organization owner', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    expect($group->organization->is($org))->toBeTrue();
});
```

- [ ] **Step 2: Test ausführen — FAIL** (`Package not found`)

- [ ] **Step 3: Implementieren**

Enums:
```php
enum PackageType: string { case Composer = 'composer'; case Npm = 'npm'; }
enum SyncStatus: string  { case Pending = 'pending'; case Syncing = 'syncing'; case Synced = 'synced'; case Failed = 'failed'; }
enum TokenAbility: string { case Read = 'read'; case Publish = 'publish'; }
```

Eine Migration `create_registry_core_tables.php`:
```php
Schema::create('packages', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('type');                    // PackageType
    $table->string('name');                    // vendor/name bzw. @scope/name
    $table->string('description')->nullable();
    $table->string('repository_url')->nullable();
    $table->string('sync_status')->default('pending');
    $table->text('sync_error')->nullable();
    $table->timestamp('synced_at')->nullable();
    $table->timestamps();
    $table->unique(['type', 'name']);
});
Schema::create('package_versions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('package_id')->constrained()->cascadeOnDelete();
    $table->string('version');                 // normalisiert, z.B. 1.2.0.0
    $table->string('version_pretty');          // z.B. v1.2.0
    $table->string('source_reference');        // commit sha / tag
    $table->jsonb('metadata');                 // composer.json des Tags
    $table->string('dist_path')->nullable();   // Pfad auf artifacts-Disk
    $table->timestamp('released_at')->nullable();
    $table->timestamps();
    $table->unique(['package_id', 'version']);
});
Schema::create('groups', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('organization_id')->nullable()->constrained();
    $table->string('name');
    $table->string('slug')->unique();
    $table->boolean('public')->default(false);
    $table->timestamps();
});
Schema::create('group_package', function (Blueprint $table) {
    $table->foreignUuid('group_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('package_id')->constrained()->cascadeOnDelete();
    $table->string('version_constraint')->nullable();
    $table->timestamp('available_until')->nullable();
    $table->primary(['group_id', 'package_id']);
});
Schema::create('domains', function (Blueprint $table) {   // genutzt ab v0.2
    $table->uuid('id')->primary();
    $table->foreignUuid('group_id')->constrained()->cascadeOnDelete();
    $table->string('hostname')->unique();
    $table->timestamps();
});
Schema::create('registry_tokens', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('group_id')->nullable()->constrained()->cascadeOnDelete(); // null = alle Gruppen der Org
    $table->string('name');
    $table->string('token_hash', 64)->unique();  // sha256
    $table->string('ability')->default('read');
    $table->timestamp('last_used_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();
});
```

Models (alle mit `HasFactory, HasUuids`, Enum-Casts, `metadata => 'array'`):
- `Package`: `versions(): HasMany` (sortiert `released_at desc`), `groups(): BelongsToMany` (`withPivot('version_constraint','available_until')`).
- `PackageVersion`: `package(): BelongsTo`.
- `Group`: `organization(): BelongsTo`, `packages(): BelongsToMany` (withPivot wie oben), `domains(): HasMany`, `tokens(): HasMany`.
- `Domain`, `RegistryToken` (`organization(): BelongsTo`, `group(): BelongsTo`, Cast `ability => TokenAbility::class`, `expires_at/last_used_at => 'datetime'`).

Factories: `PackageFactory` (`type => PackageType::Composer`, `name => fake()->unique()->slug(1).'/'.fake()->slug(2)`), `PackageVersionFactory` (`version => '1.0.0.0'`, `version_pretty => 'v1.0.0'`, `source_reference => fake()->sha1()`, `metadata => ['name' => '...', 'require' => []]`), `GroupFactory`, `RegistryTokenFactory` (Hash aus `hash('sha256', 'test-token')`).

- [ ] **Step 4: `ddev php artisan migrate:fresh && ddev php artisan test --compact`** — PASS

- [ ] **Step 5: Commit** — `feat: core schema for packages, versions, groups, domains and registry tokens`

---

### Task 4: RegistryToken-Erzeugung + Registry-Auth-Middleware

**Files:**
- Modify: `app/Models/RegistryToken.php` (Erzeugungs-/Verifikations-API)
- Create: `app/Http/Middleware/AuthenticateRegistry.php`
- Test: `tests/Feature/Registry/RegistryAuthTest.php`

- [ ] **Step 1: Failing Test**

```php
<?php // tests/Feature/Registry/RegistryAuthTest.php
use App\Models\{Group, Organization, RegistryToken};

it('issues a plaintext token once and stores only the hash', function () {
    $org = Organization::factory()->create();
    [$token, $plain] = RegistryToken::issue($org, name: 'ci', group: null);

    expect($plain)->toStartWith('kfx_')
        ->and($token->token_hash)->toBe(hash('sha256', $plain))
        ->and(RegistryToken::findByPlainText($plain)?->is($token))->toBeTrue();
});

it('rejects expired tokens', function () {
    $org = Organization::factory()->create();
    [$token, $plain] = RegistryToken::issue($org, name: 'old', group: null);
    $token->update(['expires_at' => now()->subDay()]);

    expect(RegistryToken::findByPlainText($plain))->toBeNull();
});
```

- [ ] **Step 2: Ausführen — FAIL** (`issue not defined`)

- [ ] **Step 3: Implementieren** (in `RegistryToken`)

```php
/** @return array{0: self, 1: string} */
public static function issue(Organization $org, string $name, ?Group $group, TokenAbility $ability = TokenAbility::Read, ?\DateTimeInterface $expiresAt = null): array
{
    $plain = 'kfx_'.Str::random(40);
    $token = static::create([
        'organization_id' => $org->id,
        'group_id' => $group?->id,
        'name' => $name,
        'token_hash' => hash('sha256', $plain),
        'ability' => $ability,
        'expires_at' => $expiresAt,
    ]);

    return [$token, $plain];
}

public static function findByPlainText(string $plain): ?self
{
    return static::query()
        ->where('token_hash', hash('sha256', $plain))
        ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
        ->first();
}
```

`AuthenticateRegistry`-Middleware (Composer sendet HTTP Basic; Username ist egal, Passwort = Token — zusätzlich Token als Basic-Username akzeptieren, weil manche Clients `token:` senden):
```php
<?php
namespace App\Http\Middleware;

use App\Models\RegistryToken;
use Closure;
use Illuminate\Http\Request;

class AuthenticateRegistry
{
    public function handle(Request $request, Closure $next)
    {
        $candidate = $request->getPassword() ?: $request->getUser();
        $token = $candidate ? RegistryToken::findByPlainText($candidate) : null;

        if ($token) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('registryToken', $token); // null = anonym; ACL entscheidet
        return $next($request);
    }
}
```
Registrieren in `bootstrap/app.php` als Alias `registry.auth`. Wichtig: Middleware lehnt **nicht selbst ab** — ob anonym erlaubt ist (public Group), entscheidet ausschließlich der `RegistryAccessService` (eine ACL-Schicht, Spec §2.3).

- [ ] **Step 4: Test grün**, **Step 5: Commit** — `feat: registry tokens with hashed storage and basic-auth middleware`

---

### Task 5: RegistryAccessService — die eine ACL-Schicht (+ Matrix-Tests)

**Files:**
- Create: `app/Services/RegistryAccessService.php`
- Test: `tests/Feature/Registry/AclMatrixTest.php`

- [ ] **Step 1: Failing Test (Matrix — Spec §11)**

```php
<?php // tests/Feature/Registry/AclMatrixTest.php
use App\Models\{Group, Organization, Package, RegistryToken};
use App\Services\RegistryAccessService;

beforeEach(function () {
    $this->svc = app(RegistryAccessService::class);
    $this->orgA = Organization::factory()->create();
    $this->orgB = Organization::factory()->create();
    $this->groupA = Group::factory()->for($this->orgA)->create();
    $this->groupB = Group::factory()->for($this->orgB)->create();
    $this->pkgA = Package::factory()->create();
    $this->pkgB = Package::factory()->create();
    $this->groupA->packages()->attach($this->pkgA);
    $this->groupB->packages()->attach($this->pkgB);
});

it('grants a group-scoped token access only to its group', function () {
    [, $plain] = RegistryToken::issue($this->orgA, 'a', $this->groupA);
    $token = RegistryToken::findByPlainText($plain);

    expect($this->svc->canAccessGroup($token, $this->groupA))->toBeTrue()
        ->and($this->svc->canAccessGroup($token, $this->groupB))->toBeFalse();
});

it('grants an org-wide token access to all groups of its org only', function () {
    [, $plain] = RegistryToken::issue($this->orgA, 'a', group: null);
    $token = RegistryToken::findByPlainText($plain);

    expect($this->svc->canAccessGroup($token, $this->groupA))->toBeTrue()
        ->and($this->svc->canAccessGroup($token, $this->groupB))->toBeFalse();
});

it('denies anonymous access to private groups but allows public ones', function () {
    expect($this->svc->canAccessGroup(null, $this->groupA))->toBeFalse();
    $this->groupA->update(['public' => true]);
    expect($this->svc->canAccessGroup(null, $this->groupA->fresh()))->toBeTrue();
});

it('lists only packages assigned to the group and not expired', function () {
    $this->groupA->packages()->updateExistingPivot($this->pkgA->id, ['available_until' => now()->subDay()]);
    expect($this->svc->packagesFor($this->groupA)->pluck('id'))
        ->not->toContain($this->pkgA->id);
});
```

- [ ] **Step 2: FAIL** (`RegistryAccessService not found`)

- [ ] **Step 3: Implementieren**

```php
<?php
namespace App\Services;

use App\Models\{Group, Package, RegistryToken};
use Illuminate\Database\Eloquent\Collection;

class RegistryAccessService
{
    public function canAccessGroup(?RegistryToken $token, Group $group): bool
    {
        if ($group->public) {
            return true;
        }
        if (! $token) {
            return false;
        }
        return $token->group_id === $group->id
            || ($token->group_id === null && $token->organization_id === $group->organization_id);
    }

    /** @return Collection<int, Package> Pool-Pakete der Gruppe ohne abgelaufene Zuweisungen */
    public function packagesFor(Group $group): Collection
    {
        return $group->packages()
            ->where(fn ($q) => $q->whereNull('available_until')->orWhere('available_until', '>', now()))
            ->get();
    }

    public function canAccessPackage(?RegistryToken $token, Group $group, Package $package): bool
    {
        return $this->canAccessGroup($token, $group)
            && $this->packagesFor($group)->contains('id', $package->id);
    }
}
```

- [ ] **Step 4: Test grün**, **Step 5: Commit** — `feat: central registry access service with acl matrix tests`

---

### Task 6: GitRepository-Service (clone, Tags, Datei@Ref, Archiv)

**Files:**
- Create: `app/Services/Vcs/GitRepository.php`
- Test: `tests/Unit/GitRepositoryTest.php` (nutzt ein lokal erzeugtes Fixture-Repo, kein Netz)

- [ ] **Step 1: Failing Test**

```php
<?php // tests/Unit/GitRepositoryTest.php
use App\Services\Vcs\GitRepository;
use Illuminate\Support\Facades\Process;

function makeFixtureRepo(): string
{
    $dir = sys_get_temp_dir().'/kfx-fixture-'.uniqid();
    Process::path(sys_get_temp_dir())->run("git init -b main {$dir}")->throw();
    file_put_contents($dir.'/composer.json', json_encode([
        'name' => 'acme/demo', 'description' => 'Demo', 'require' => ['php' => '>=8.2'],
    ]));
    foreach (['git add .', 'git -c user.email=t@t -c user.name=t commit -m init', 'git tag v1.0.0'] as $cmd) {
        Process::path($dir)->run($cmd)->throw();
    }
    return $dir;
}

it('mirrors a repo, lists tags and reads composer.json at a tag', function () {
    $fixture = makeFixtureRepo();
    $repo = new GitRepository('file://'.$fixture, 'test-pkg-id');
    $repo->sync();

    expect($repo->tags())->toContain('v1.0.0');
    $json = json_decode($repo->fileAtRef('v1.0.0', 'composer.json'), true);
    expect($json['name'])->toBe('acme/demo');
});

it('creates a zip archive for a ref', function () {
    $fixture = makeFixtureRepo();
    $repo = new GitRepository('file://'.$fixture, 'test-pkg-id-2');
    $repo->sync();
    $zip = $repo->archiveZip('v1.0.0');

    expect(file_exists($zip))->toBeTrue()->and(filesize($zip))->toBeGreaterThan(0);
    unlink($zip);
});
```

- [ ] **Step 2: FAIL**, dann **Step 3: Implementieren**

```php
<?php
namespace App\Services\Vcs;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class GitRepository
{
    private string $mirrorPath;

    public function __construct(private readonly string $url, string $storageKey)
    {
        $this->mirrorPath = storage_path('app/vcs/'.$storageKey.'.git');
    }

    public function sync(): void
    {
        if (is_dir($this->mirrorPath)) {
            $this->run(['git', 'fetch', '--prune', '--tags', 'origin']);
            return;
        }
        @mkdir(dirname($this->mirrorPath), 0775, true);
        $result = Process::timeout(300)->run(['git', 'clone', '--mirror', $this->url, $this->mirrorPath]);
        if (! $result->successful()) {
            throw new RuntimeException('git clone failed: '.$result->errorOutput());
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return array_values(array_filter(explode("\n", $this->run(['git', 'tag', '-l'])->output())));
    }

    public function commitFor(string $ref): string
    {
        return trim($this->run(['git', 'rev-list', '-n', '1', $ref])->output());
    }

    public function fileAtRef(string $ref, string $path): string
    {
        return $this->run(['git', 'show', "{$ref}:{$path}"])->output();
    }

    public function archiveZip(string $ref): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kfx-dist-').'.zip';
        $this->run(['git', 'archive', '--format=zip', '-o', $tmp, $ref]);
        return $tmp;
    }

    private function run(array $command): \Illuminate\Contracts\Process\ProcessResult
    {
        $result = Process::path($this->mirrorPath)->timeout(120)->run($command);
        if (! $result->successful()) {
            throw new RuntimeException(implode(' ', $command).' failed: '.$result->errorOutput());
        }
        return $result;
    }
}
```

- [ ] **Step 4: Test grün** (git ist in DDEV/CI vorhanden), **Step 5: Commit** — `feat: git repository service for mirror, tags, file-at-ref and zip archive`

---

### Task 7: SyncPackage-Job (Tags → Versionen)

**Files:**
- Create: `app/Jobs/SyncPackage.php`
- Test: `tests/Feature/SyncPackageTest.php` (nutzt `makeFixtureRepo()` — nach `tests/Support/FixtureRepo.php` extrahieren und in beiden Tests verwenden)

- [ ] **Step 1: Failing Test**

```php
<?php // tests/Feature/SyncPackageTest.php
use App\Enums\SyncStatus;
use App\Jobs\SyncPackage;
use App\Models\Package;
use Tests\Support\FixtureRepo;

it('imports tagged versions with normalized version strings', function () {
    $fixture = FixtureRepo::make();   // wie in Task 6, + zweiter Tag v1.1.0
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.$fixture]);

    (new SyncPackage($pkg))->handle();

    $pkg->refresh();
    expect($pkg->sync_status)->toBe(SyncStatus::Synced)
        ->and($pkg->versions()->pluck('version_pretty')->all())->toContain('v1.0.0', 'v1.1.0')
        ->and($pkg->versions()->where('version_pretty', 'v1.0.0')->first()->version)->toBe('1.0.0.0');
});

it('records failures instead of throwing away the error', function () {
    $pkg = Package::factory()->create(['repository_url' => 'file:///does/not/exist']);
    (new SyncPackage($pkg))->handle();

    expect($pkg->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($pkg->fresh()->sync_error)->not->toBeEmpty();
});
```

- [ ] **Step 2: FAIL**, **Step 3: Implementieren**

```php
<?php
namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Models\Package;
use App\Services\Vcs\GitRepository;
use Composer\Semver\VersionParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncPackage implements ShouldQueue
{
    use Queueable;

    public function __construct(public Package $package) {}

    public function handle(): void
    {
        $this->package->update(['sync_status' => SyncStatus::Syncing]);

        try {
            $repo = new GitRepository($this->package->repository_url, $this->package->id);
            $repo->sync();
            $parser = new VersionParser;

            foreach ($repo->tags() as $tag) {
                try {
                    $normalized = $parser->normalize($tag);
                } catch (\UnexpectedValueException) {
                    continue; // kein Versions-Tag
                }
                $composerJson = json_decode($repo->fileAtRef($tag, 'composer.json'), true);
                if (! is_array($composerJson)) {
                    continue;
                }
                $this->package->versions()->updateOrCreate(
                    ['version' => $normalized],
                    [
                        'version_pretty' => $tag,
                        'source_reference' => $repo->commitFor($tag),
                        'metadata' => $composerJson,
                        'released_at' => now(),
                    ],
                );
            }
            $this->package->update([
                'sync_status' => SyncStatus::Synced, 'sync_error' => null, 'synced_at' => now(),
                'description' => $this->package->versions()->latest('released_at')->first()?->metadata['description'] ?? $this->package->description,
            ]);
        } catch (Throwable $e) {
            $this->package->update(['sync_status' => SyncStatus::Failed, 'sync_error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 4: grün**, **Step 5: Commit** — `feat: sync job importing tagged versions from vcs`

---

### Task 8: ComposerMetadataBuilder (p2-Format)

**Files:**
- Create: `app/Services/Composer/ComposerMetadataBuilder.php`
- Test: `tests/Unit/ComposerMetadataBuilderTest.php`

- [ ] **Step 1: Failing Test**

```php
<?php // tests/Unit/ComposerMetadataBuilderTest.php
use App\Models\{Group, Package, PackageVersion};
use App\Services\Composer\ComposerMetadataBuilder;

it('builds minified composer v2 metadata with dist urls scoped to the group', function () {
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    PackageVersion::factory()->for($pkg)->create([
        'version' => '1.0.0.0', 'version_pretty' => 'v1.0.0',
        'metadata' => ['name' => 'acme/demo', 'require' => ['php' => '>=8.2']],
    ]);
    $group = Group::factory()->create(['slug' => 'kadenz']);

    $doc = app(ComposerMetadataBuilder::class)->build($pkg, $group, 'https://registry.test');
    $versions = \Composer\MetadataMinifier\MetadataMinifier::expand($doc['packages']['acme/demo']);

    expect($versions[0]['version'])->toBe('v1.0.0')
        ->and($versions[0]['dist']['url'])->toBe('https://registry.test/r/kadenz/dists/acme/demo/1.0.0.0.zip')
        ->and($versions[0]['dist']['type'])->toBe('zip')
        ->and($versions[0]['require']['php'])->toBe('>=8.2');
});
```

- [ ] **Step 2: FAIL**, **Step 3: Implementieren**

```php
<?php
namespace App\Services\Composer;

use App\Models\{Group, Package};
use Composer\MetadataMinifier\MetadataMinifier;

class ComposerMetadataBuilder
{
    /** @return array{packages: array<string, mixed>} */
    public function build(Package $package, Group $group, string $baseUrl): array
    {
        $versions = $package->versions()->orderByDesc('released_at')->get()->map(function ($v) use ($package, $group, $baseUrl) {
            return array_merge($v->metadata, [
                'name' => $package->name,
                'version' => $v->version_pretty,
                'version_normalized' => $v->version,
                'source' => $package->repository_url ? [
                    'type' => 'git', 'url' => $package->repository_url, 'reference' => $v->source_reference,
                ] : null,
                'dist' => [
                    'type' => 'zip',
                    'url' => "{$baseUrl}/r/{$group->slug}/dists/{$package->name}/{$v->version}.zip",
                    'reference' => $v->source_reference,
                ],
            ]);
        })->filter()->values()->all();

        return ['packages' => [$package->name => MetadataMinifier::minify($versions)]];
    }
}
```

- [ ] **Step 4: grün**, **Step 5: Commit** — `feat: composer v2 metadata builder with minified output`

---

### Task 9: Composer-HTTP-Endpoints — packages.json + p2

**Files:**
- Create: `routes/registry.php`, `app/Http/Controllers/Registry/ComposerController.php`
- Modify: `bootstrap/app.php` (Route-Datei + Middleware-Alias registrieren)
- Test: `tests/Feature/Registry/ComposerMetadataTest.php`

- [ ] **Step 1: Failing Test**

```php
<?php // tests/Feature/Registry/ComposerMetadataTest.php
use App\Models\{Group, Organization, Package, PackageVersion, RegistryToken};

function tokenHeaderFor(Group $group): array
{
    [, $plain] = RegistryToken::issue($group->organization, 'test', $group);
    return ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];
}

it('serves packages.json with metadata-url and available packages', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/packages.json');

    $res->assertOk()
        ->assertJsonPath('metadata-url', '/r/kadenz/p2/%package%.json')
        ->assertJsonPath('available-packages.0', 'acme/demo');
});

it('serves p2 metadata for an assigned package', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    PackageVersion::factory()->for($pkg)->create();
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))
        ->getJson('/r/kadenz/p2/acme/demo.json')
        ->assertOk()->assertJsonStructure(['packages' => ['acme/demo']]);
});

it('returns 401 without token and 404 for unassigned packages', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $other = Package::factory()->create(['name' => 'acme/secret']);
    PackageVersion::factory()->for($other)->create();

    $this->getJson('/r/kadenz/packages.json')->assertUnauthorized();
    $this->withHeaders(tokenHeaderFor($group))
        ->getJson('/r/kadenz/p2/acme/secret.json')->assertNotFound(); // nie 403: kein Leak, ob es das Paket gibt
});
```

- [ ] **Step 2: FAIL**, **Step 3: Implementieren**

`routes/registry.php`:
```php
<?php
use App\Http\Controllers\Registry\ComposerController;
use Illuminate\Support\Facades\Route;

Route::prefix('/r/{group:slug}')->middleware('registry.auth')->group(function () {
    Route::get('/packages.json', [ComposerController::class, 'root']);
    Route::get('/p2/{vendor}/{name}.json', [ComposerController::class, 'metadata'])
        ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.~-]+']);
    Route::get('/dists/{vendor}/{name}/{version}.zip', [ComposerController::class, 'dist'])
        ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.-]+', 'version' => '[^/]+']);
});
```
In `bootstrap/app.php` bei `withRouting(...)`: `then: fn () => Route::middleware('api')->group(base_path('routes/registry.php'))` ergänzen (bzw. `->withRouting(web: ..., then: ...)`) und Alias `'registry.auth' => AuthenticateRegistry::class` unter `withMiddleware` registrieren.

`ComposerController` (root + metadata; `dist` folgt in Task 10 — hier zunächst `abort(501)` als Body):
```php
<?php
namespace App\Http\Controllers\Registry;

use App\Http\Controllers\Controller;
use App\Models\{Group, Package};
use App\Services\Composer\ComposerMetadataBuilder;
use App\Services\RegistryAccessService;
use Illuminate\Http\Request;

class ComposerController extends Controller
{
    public function __construct(
        private readonly RegistryAccessService $access,
        private readonly ComposerMetadataBuilder $metadata,
    ) {}

    public function root(Request $request, Group $group)
    {
        $this->authorizeGroup($request, $group);

        return response()->json([
            'metadata-url' => "/r/{$group->slug}/p2/%package%.json",
            'available-packages' => $this->access->packagesFor($group)->pluck('name')->sort()->values(),
        ]);
    }

    public function metadata(Request $request, Group $group, string $vendor, string $name)
    {
        $this->authorizeGroup($request, $group);
        $package = $this->findAccessible($request, $group, "{$vendor}/{$name}");

        return response()->json($this->metadata->build($package, $group, $request->getSchemeAndHttpHost()));
    }

    protected function authorizeGroup(Request $request, Group $group): void
    {
        $token = $request->attributes->get('registryToken');
        if (! $this->access->canAccessGroup($token, $group)) {
            abort($token ? 404 : 401, 'Authentication required for this registry.');
        }
    }

    protected function findAccessible(Request $request, Group $group, string $fullName): Package
    {
        $token = $request->attributes->get('registryToken');
        $package = Package::where('type', 'composer')->where('name', $fullName)->first();
        if (! $package || ! $this->access->canAccessPackage($token, $group, $package)) {
            abort(404); // bewusst kein 403 — Existenz nicht leaken
        }
        return $package;
    }
}
```

- [ ] **Step 4: grün**, **Step 5: Commit** — `feat: composer packages.json and p2 metadata endpoints with acl`

---

### Task 10: Dist-Build + authentifizierter Download

**Files:**
- Modify: `app/Http/Controllers/Registry/ComposerController.php` (`dist`-Action)
- Test: `tests/Feature/Registry/ComposerDistTest.php`

- [ ] **Step 1: Failing Test**

```php
<?php // tests/Feature/Registry/ComposerDistTest.php
use App\Jobs\SyncPackage;
use App\Models\{Group, Organization, Package};
use Illuminate\Support\Facades\Storage;
use Tests\Support\FixtureRepo;

it('builds the zip lazily, stores it on the artifacts disk and streams it', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(tokenHeaderFor($group))->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip');

    $res->assertOk()->assertHeader('content-type', 'application/zip');
    Storage::disk('artifacts')->assertExists('dists/'.$pkg->id.'/1.0.0.0.zip');
});

it('denies dist download without access', function () {
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    // Paket NICHT zugewiesen
    $this->withHeaders(tokenHeaderFor($group))
        ->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertNotFound();
});
```
(`tokenHeaderFor` aus Task 9 nach `tests/Pest.php` als globale Helper-Funktion verschieben.)

- [ ] **Step 2: FAIL**, **Step 3: Implementieren** (`dist`-Action)

```php
public function dist(Request $request, Group $group, string $vendor, string $name, string $version)
{
    $this->authorizeGroup($request, $group);
    $package = $this->findAccessible($request, $group, "{$vendor}/{$name}");
    $pkgVersion = $package->versions()->where('version', $version)->firstOrFail();

    $disk = \Storage::disk('artifacts');
    $path = $pkgVersion->dist_path ?? "dists/{$package->id}/{$version}.zip";

    if (! $disk->exists($path)) {
        $repo = new \App\Services\Vcs\GitRepository($package->repository_url, $package->id);
        $repo->sync();
        $tmp = $repo->archiveZip($pkgVersion->source_reference);
        $disk->putFileAs(dirname($path), new \Illuminate\Http\File($tmp), basename($path));
        unlink($tmp);
        $pkgVersion->update(['dist_path' => $path]);
    }

    return response()->streamDownload(
        fn () => fpassthru($disk->readStream($path)),
        "{$name}-{$pkgVersion->version_pretty}.zip",
        ['Content-Type' => 'application/zip'],
    );
}
```

- [ ] **Step 4: grün**, **Step 5: Commit** — `feat: lazy dist build and authenticated zip streaming`

---

### Task 11: End-to-End-Contract-Test „Composer-Flow"

**Files:**
- Test: `tests/Feature/Registry/ComposerFlowTest.php`

- [ ] **Step 1: Test schreiben (direkt grün erwartet — er verifiziert das Zusammenspiel)**

```php
<?php // tests/Feature/Registry/ComposerFlowTest.php
use App\Jobs\SyncPackage;
use App\Models\{Group, Organization, Package};
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FixtureRepo;

it('completes the full composer client flow: root -> p2 -> dist', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);
    $headers = tokenHeaderFor($group);

    // 1. Wie `composer update`: Root-Dokument
    $root = $this->withHeaders($headers)->getJson('/r/kadenz/packages.json')->assertOk()->json();
    expect($root['available-packages'])->toContain('acme/demo');

    // 2. Metadaten über die metadata-url-Vorlage
    $metaUrl = str_replace('%package%', 'acme/demo', $root['metadata-url']);
    $meta = $this->withHeaders($headers)->getJson($metaUrl)->assertOk()->json();
    $version = MetadataMinifier::expand($meta['packages']['acme/demo'])[0];

    // 3. Dist-Download über die URL aus den Metadaten
    $distPath = parse_url($version['dist']['url'], PHP_URL_PATH);
    $this->withHeaders($headers)->get($distPath)->assertOk();
});
```

- [ ] **Step 2: Ausführen — PASS erwartet.** Falls FAIL: Fehler liegt im Zusammenspiel (URL-Bau vs. Routen) — fixen, nicht den Test aufweichen.

- [ ] **Step 3: Manueller Smoke-Test (dokumentieren, nicht automatisieren)**

```bash
ddev php artisan tinker --execute="..."  # Paket + Gruppe + Token seeden (Seeder DevSeeder anlegen)
# auf dem Host:
composer config -g http-basic.kontorfix.ddev.site token kfx_...
# in einem Wegwerf-Projekt:
composer require acme/demo --repository='{"type":"composer","url":"https://kontorfix.ddev.site/r/kadenz"}'
```
Ergebnis als Kommentar in den PR/Commit-Text aufnehmen.

- [ ] **Step 4: Commit** — `test: end-to-end composer client contract flow`

---

### Task 12: Admin-GUI — Pakete (Index + Anlegen mit Inline-Gruppen)

**Files:**
- Create: `app/Http/Controllers/Admin/PackageController.php`, `app/Http/Requests/Admin/StorePackageRequest.php`
- Create: `resources/js/pages/admin/packages/Index.vue`, `resources/js/components/kontorfix/{TypeBadge.vue,StatusPill.vue}`
- Modify: `routes/web.php`, Sidebar-Navigation des Starter-Kits (`resources/js/components/AppSidebar.vue` o.ä. — Datei im Starter-Kit lokalisieren)
- Test: `tests/Feature/Admin/PackageCrudTest.php`

**Brand-Setup (einmalig in diesem Task):** In `resources/css/app.css` die Kontorfix-Farbwelt als Tailwind-4-Theme-Tokens ergänzen (Werte aus `docs/brand/README.md`):
```css
@theme {
  --color-ink: #0D141F;
  --color-panel: #151F2E;
  --color-copper: #D07A45;
  --color-copper-hi: #E29260;
  --color-verdigris: #7FB5A2;
  --color-paper: #E9E4D9;
}
```
`docs/brand/favicon.svg` nach `public/favicon.svg` kopieren, App-Name in `.env`/`config/app.php` auf `Kontorfix`.

- [ ] **Step 1: Failing Backend-Test**

```php
<?php // tests/Feature/Admin/PackageCrudTest.php
use App\Enums\UserRole;
use App\Jobs\SyncPackage;
use App\Models\{Group, Package, User};
use Illuminate\Support\Facades\Queue;

it('lists packages for admins', function () {
    Package::factory()->count(2)->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->get('/admin/packages')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/Index')->has('packages.data', 2));
});

it('creates a package, assigns groups inline and dispatches sync', function () {
    Queue::fake();
    $groups = Group::factory()->count(2)->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->post('/admin/packages', [
            'type' => 'composer',
            'repository_url' => 'https://git.example.com/acme/demo.git',
            'name' => 'acme/demo',
            'group_ids' => $groups->pluck('id')->all(),
        ])->assertRedirect();

    $pkg = Package::where('name', 'acme/demo')->firstOrFail();
    expect($pkg->groups)->toHaveCount(2);
    Queue::assertPushed(SyncPackage::class);
});

it('forbids members from managing packages', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->get('/admin/packages')->assertForbidden();
});
```

- [ ] **Step 2: FAIL**, **Step 3: Backend implementieren**

Middleware-Kurzform: eigene `EnsureUserRole`-Middleware mit Alias `role`:
```php
<?php
namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;

class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        abort_unless(in_array($request->user()?->role?->value, $roles, true), 403);
        return $next($request);
    }
}
```

`routes/web.php`:
```php
Route::middleware(['auth', 'role:admin,maintainer'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('packages', Admin\PackageController::class)->only(['index', 'store', 'destroy']);
    Route::resource('groups', Admin\GroupController::class)->only(['index', 'store', 'destroy']);   // Task 13
    Route::resource('tokens', Admin\TokenController::class)->only(['index', 'store', 'destroy']);    // Task 14
    Route::get('package-search', Admin\PackageSearchController::class)->name('package-search');      // Task 13
});
```

`StorePackageRequest`:
```php
public function rules(): array
{
    return [
        'type' => ['required', Rule::enum(PackageType::class)],
        'name' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/',
                   Rule::unique('packages')->where('type', $this->input('type'))],
        'repository_url' => ['required', 'string', 'max:500'],
        'group_ids' => ['array'],
        'group_ids.*' => ['uuid', 'exists:groups,id'],
    ];
}
```

`PackageController`:
```php
public function index()
{
    return Inertia::render('admin/packages/Index', [
        'packages' => Package::withCount('groups')->latest()->paginate(25)->through(fn ($p) => [
            'id' => $p->id, 'type' => $p->type, 'name' => $p->name,
            'sync_status' => $p->sync_status, 'sync_error' => $p->sync_error,
            'groups_count' => $p->groups_count, 'synced_at' => $p->synced_at?->diffForHumans(),
        ]),
        'groups' => Group::orderBy('name')->get(['id', 'name', 'slug']),
    ]);
}

public function store(StorePackageRequest $request)
{
    $package = Package::create($request->safe()->except('group_ids'));
    $package->groups()->sync($request->validated('group_ids', []));
    SyncPackage::dispatch($package);

    return back()->with('success', "Paket {$package->name} angelegt — Sync gestartet.");
}
```

- [ ] **Step 4: Backend-Tests grün**

- [ ] **Step 5: Vue-Seite bauen** (Starter-Kit-Layout verwenden; vorhandene UI-Komponenten des Kits — Button, Input, Dialog, Select — wiederverwenden, Importpfade beim Implementieren im Kit nachschlagen)

`resources/js/components/kontorfix/TypeBadge.vue`:
```vue
<script setup lang="ts">
defineProps<{ type: 'composer' | 'npm' }>()
</script>
<template>
  <span class="rounded px-1.5 py-0.5 font-mono text-[10px] tracking-wider"
        :class="type === 'composer' ? 'bg-copper/15 text-copper-hi' : 'bg-verdigris/15 text-verdigris'">
    {{ type }}
  </span>
</template>
```

`StatusPill.vue`:
```vue
<script setup lang="ts">
const props = defineProps<{ status: 'pending' | 'syncing' | 'synced' | 'failed' }>()
const styles = {
  pending: 'bg-muted text-muted-foreground', syncing: 'bg-amber-500/15 text-amber-500',
  synced: 'bg-emerald-500/15 text-emerald-500', failed: 'bg-red-500/15 text-red-500',
} as const
const labels = { pending: 'Wartet', syncing: 'Läuft…', synced: 'Synchronisiert', failed: 'Fehlgeschlagen' } as const
</script>
<template>
  <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="styles[props.status]">{{ labels[props.status] }}</span>
</template>
```

`pages/admin/packages/Index.vue` — Tabelle (Name in `font-mono`, TypeBadge, StatusPill, Gruppen-Anzahl, Sync-Zeit) + „Paket hinzufügen"-Dialog mit Feldern Typ (Select composer/npm), Name, Repository-URL und **Gruppen-Multiselect (Checkbox-Liste der übergebenen `groups`)** — die Inline-Zuweisung Richtung „Paket → Gruppen". Formular via `useForm` von Inertia, `form.post('/admin/packages')`, bei Erfolg Dialog schließen + `form.reset()`.

- [ ] **Step 6: Sidebar-Navigation ergänzen** — Einträge Übersicht (`/dashboard`, vorhanden), Pakete (`/admin/packages`), Gruppen (`/admin/groups`), Tokens (`/admin/tokens`). Logo im Sidebar-Header durch `docs/brand/logo-mark.svg`-Inhalt (inline SVG-Komponente `KontorfixMark.vue`) ersetzen, Wortmarke `kontor<span class="text-copper">fix</span>`.

- [ ] **Step 7: Build + Verifizieren + Commit**

```bash
ddev npm run build && ddev php artisan test --compact
git add -A && git commit -m "feat: admin package management with inline group assignment"
```

---

### Task 13: Admin-GUI — Gruppen-Slide-over mit flüssiger Paket-Zuweisung

**Files:**
- Create: `app/Http/Controllers/Admin/{GroupController,PackageSearchController}.php`, `app/Http/Requests/Admin/StoreGroupRequest.php`
- Create: `resources/js/pages/admin/groups/Index.vue`, `resources/js/components/kontorfix/{GroupSheet.vue,PackagePicker.vue}`
- Test: `tests/Feature/Admin/{GroupCrudTest,PackageSearchTest}.php`

- [ ] **Step 1: Failing Tests**

```php
<?php // tests/Feature/Admin/GroupCrudTest.php
use App\Enums\UserRole;
use App\Models\{Group, Package, User};

it('creates a group with slug and assigns existing pool packages', function () {
    $pkgs = Package::factory()->count(2)->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->post('/admin/groups', [
            'name' => 'Kadenz GmbH',
            'slug' => 'kadenz',
            'public' => false,
            'package_ids' => $pkgs->pluck('id')->all(),
        ])->assertRedirect();

    $group = Group::where('slug', 'kadenz')->firstOrFail();
    expect($group->packages)->toHaveCount(2);
});

it('rejects duplicate slugs', function () {
    Group::factory()->create(['slug' => 'kadenz']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->post('/admin/groups', ['name' => 'X', 'slug' => 'kadenz'])
        ->assertSessionHasErrors('slug');
});
```

```php
<?php // tests/Feature/Admin/PackageSearchTest.php
use App\Enums\UserRole;
use App\Models\{Package, User};

it('searches the global pool by name fragment', function () {
    Package::factory()->create(['name' => 'kadenz/tickets-core']);
    Package::factory()->create(['name' => 'acme/unrelated']);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->getJson('/admin/package-search?q=tick')
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'kadenz/tickets-core');
});
```

- [ ] **Step 2: FAIL**, **Step 3: Backend implementieren**

`StoreGroupRequest`: `name` required; `slug` required, `regex:/^[a-z0-9-]+$/`, `unique:groups,slug`; `public` boolean; `package_ids` array of uuid/exists. `GroupController::index` liefert Gruppen mit `withCount('packages')` + `domains`-Platzhalter (leeres Array bis v0.2); `store` erstellt + `packages()->sync(...)`, Organization = Operator-Org des eingeloggten Users (`$request->user()->organization_id`).

`PackageSearchController` (Single-Action):
```php
public function __invoke(Request $request)
{
    $q = (string) $request->query('q', '');

    return Package::query()
        ->when($q !== '', fn ($query) => $query->where('name', 'ilike', "%{$q}%"))
        ->orderBy('name')->limit(8)
        ->get(['id', 'name', 'type'])
        ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type]);
}
```

- [ ] **Step 4: Backend grün**

- [ ] **Step 5: PackagePicker.vue — das Herzstück der flüssigen Zuweisung**

```vue
<script setup lang="ts">
import { ref, watch } from 'vue'
import TypeBadge from './TypeBadge.vue'

type Pkg = { id: string; name: string; type: 'composer' | 'npm' }
const model = defineModel<Pkg[]>({ required: true })   // ausgewählte Pakete
const query = ref(''); const results = ref<Pkg[]>([]); const open = ref(false)
let t: ReturnType<typeof setTimeout>

watch(query, (q) => {
  clearTimeout(t)
  if (!q) { results.value = []; open.value = false; return }
  t = setTimeout(async () => {
    const res = await fetch(`/admin/package-search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
    const all: Pkg[] = await res.json()
    results.value = all.filter(p => !model.value.some(s => s.id === p.id))
    open.value = true
  }, 200)
})
const add = (p: Pkg) => { model.value = [...model.value, p]; query.value = '' }
const remove = (p: Pkg) => { model.value = model.value.filter(x => x.id !== p.id) }
const emit = defineEmits<{ createNew: [name: string] }>()
</script>

<template>
  <div class="rounded-lg border border-input bg-background">
    <input v-model="query" placeholder="Paket suchen…" class="w-full bg-transparent px-3 py-2 font-mono text-sm outline-none" />
    <div v-if="open" class="border-t border-input">
      <button v-for="p in results" :key="p.id" type="button" @click="add(p)"
              class="flex w-full items-center gap-2 px-3 py-1.5 text-left font-mono text-xs hover:bg-accent">
        <TypeBadge :type="p.type" /> {{ p.name }}
      </button>
      <button type="button" @click="emit('createNew', query)"
              class="w-full border-t border-dashed border-input px-3 py-1.5 text-left text-xs text-verdigris hover:bg-accent">
        ＋ Neues Paket „{{ query }}…“ anlegen und zuweisen
      </button>
    </div>
  </div>
  <div class="mt-2 flex flex-wrap gap-1.5">
    <span v-for="p in model" :key="p.id" class="inline-flex items-center gap-1.5 rounded-md border border-input bg-muted px-2 py-1 font-mono text-xs">
      <TypeBadge :type="p.type" /> {{ p.name }}
      <button type="button" class="text-muted-foreground" @click="remove(p)">×</button>
    </span>
  </div>
</template>
```

- [ ] **Step 6: GroupSheet.vue + Index.vue**

`GroupSheet.vue`: Slide-over (Sheet/Dialog-Komponente des Starter-Kits) mit `useForm({ name: '', slug: '', public: false, package_ids: [] })`; Name-Input mit `watch`, das den Slug via `name.toLowerCase().replace(/[^a-z0-9]+/g, '-')` vorbefüllt (manuell überschreibbar); Hint-Zeile „Erreichbar unter `<code>{origin}/r/{slug}</code>`"; `PackagePicker` mit `v-model` einer lokalen `selected: Pkg[]`-Ref, vor `form.post` → `form.package_ids = selected.map(p => p.id)`. `@createNew` öffnet den „Paket hinzufügen"-Dialog aus Task 12 (als gemeinsame Komponente `CreatePackageDialog.vue` extrahieren) mit vorausgefülltem Namen; nach Erfolg wird das neue Paket der Auswahl hinzugefügt (Response-Daten via Inertia `onSuccess` + zurückgegebene `flash.createdPackage`-Prop).

`Index.vue`: Tabelle Name / Slug (mono) / Pakete-Anzahl / public-Badge, Button „＋ Neue Gruppe" öffnet das Sheet.

- [ ] **Step 7: Build + volle Suite + Commit**

```bash
ddev npm run build && ddev php artisan test --compact
git add -A && git commit -m "feat: group management with fluid package assignment sheet"
```

---

### Task 14: Admin-GUI — Tokens

**Files:**
- Create: `app/Http/Controllers/Admin/TokenController.php`, `app/Http/Requests/Admin/StoreTokenRequest.php`
- Create: `resources/js/pages/admin/tokens/Index.vue`
- Test: `tests/Feature/Admin/TokenCrudTest.php`

- [ ] **Step 1: Failing Test**

```php
<?php // tests/Feature/Admin/TokenCrudTest.php
use App\Enums\UserRole;
use App\Models\{Group, Organization, RegistryToken, User};

it('creates a token and flashes the plaintext exactly once', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();

    $res = $this->actingAs(User::factory()->for($org)->create(['role' => UserRole::Admin]))
        ->post('/admin/tokens', ['name' => 'kadenz-ci', 'organization_id' => $org->id, 'group_id' => $group->id]);

    $res->assertRedirect()->assertSessionHas('plainTextToken');
    expect(session('plainTextToken'))->toStartWith('kfx_')
        ->and(RegistryToken::where('name', 'kadenz-ci')->exists())->toBeTrue();
});

it('revokes tokens by deletion', function () {
    $org = Organization::factory()->create();
    [$token] = RegistryToken::issue($org, 'x', null);

    $this->actingAs(User::factory()->for($org)->create(['role' => UserRole::Admin]))
        ->delete("/admin/tokens/{$token->id}")->assertRedirect();

    expect(RegistryToken::find($token->id))->toBeNull();
});
```

- [ ] **Step 2: FAIL**, **Step 3: Implementieren**

`TokenController::store`:
```php
public function store(StoreTokenRequest $request)
{
    [$token, $plain] = RegistryToken::issue(
        Organization::findOrFail($request->validated('organization_id')),
        $request->validated('name'),
        $request->validated('group_id') ? Group::findOrFail($request->validated('group_id')) : null,
    );

    return back()->with('plainTextToken', $plain)->with('success', "Token {$token->name} erstellt.");
}
```
`index`: Tokens mit Org-/Gruppen-Name, `last_used_at`, `expires_at` paginiert; zusätzlich `organizations` + `groups` für das Formular. `destroy`: `$token->delete()`.

`Index.vue`: Tabelle + „Token erstellen"-Dialog (Name, Organization-Select, optional Gruppen-Select). Nach Erfolg zeigt ein Callout einmalig `flash.plainTextToken` in `font-mono` mit Copy-Button und dem Hinweis „Wird nur einmal angezeigt". Flash-Prop in `HandleInertiaRequests::share` durchreichen (`'flash' => ['plainTextToken' => fn () => session('plainTextToken'), 'success' => fn () => session('success')]`).

- [ ] **Step 4: grün**, **Step 5: Commit** — `feat: registry token management with one-time plaintext display`

---

### Task 15: CI-Workflow + Docker-Image + Compose

**Files:**
- Create: `.github/workflows/ci.yml`, `docker/Dockerfile`, `docker/entrypoint.sh`, `docker/compose.yaml`, `.dockerignore`

- [ ] **Step 1: CI-Workflow**

```yaml
# .github/workflows/ci.yml
name: CI
on:
  push: { branches: [main] }
  pull_request:

jobs:
  commitlint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with: { fetch-depth: 0 }
      - uses: wagoid/commitlint-github-action@v6
        with: { configFile: .commitlintrc.yml }

  quality:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:17
        env: { POSTGRES_DB: kontorfix, POSTGRES_USER: kfx, POSTGRES_PASSWORD: kfx }
        ports: ['5432:5432']
        options: --health-cmd pg_isready --health-interval 5s --health-retries 10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', extensions: 'pgsql, redis, zip' }
      - run: composer install --no-interaction --prefer-dist
      - run: vendor/bin/pint --test
      - run: vendor/bin/phpstan --no-progress
      - run: npm ci && npm run build
      - run: cp .env.example .env && php artisan key:generate
      - run: php artisan test --compact
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_DATABASE: kontorfix
          DB_USERNAME: kfx
          DB_PASSWORD: kfx
```
`.commitlintrc.yml`: `extends: ['@commitlint/config-conventional']`.

- [ ] **Step 2: Dockerfile (Multi-Stage, FrankenPHP)**

```dockerfile
# docker/Dockerfile
FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json vite.config.* ./
RUN npm ci
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM dunglas/frankenphp:php8.4 AS app
RUN install-php-extensions pcntl pdo_pgsql redis zip intl opcache && \
    apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize && chmod +x docker/entrypoint.sh
ENV SERVER_NAME=:8080
EXPOSE 8080
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["frankenphp", "php-server", "-r", "public/"]
```

`docker/entrypoint.sh`:
```bash
#!/bin/sh
set -e
if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
  php artisan migrate --force
  php artisan config:cache && php artisan route:cache && php artisan view:cache
elif [ "$CONTAINER_ROLE" = "worker" ]; then
  exec php artisan queue:work --tries=3 --max-time=3600
elif [ "$CONTAINER_ROLE" = "scheduler" ]; then
  exec php artisan schedule:work
fi
exec "$@"
```

- [ ] **Step 3: Compose-Stack**

```yaml
# docker/compose.yaml
services:
  app:
    image: harbor.cloud.noidee.dev/noixdev/kontorfix:latest
    ports: ['8080:8080']
    env_file: .env
    depends_on: [postgres, redis]
    healthcheck: { test: ['CMD', 'curl', '-f', 'http://localhost:8080/up'], interval: 15s, retries: 5 }
    volumes: ['artifacts:/app/storage/app']
  worker:
    image: harbor.cloud.noidee.dev/noixdev/kontorfix:latest
    environment: { CONTAINER_ROLE: worker }
    env_file: .env
    depends_on: [postgres, redis]
    volumes: ['artifacts:/app/storage/app']
  scheduler:
    image: harbor.cloud.noidee.dev/noixdev/kontorfix:latest
    environment: { CONTAINER_ROLE: scheduler }
    env_file: .env
    depends_on: [postgres, redis]
  postgres:
    image: postgres:17-alpine
    environment: { POSTGRES_DB: kontorfix, POSTGRES_USER: kontorfix, POSTGRES_PASSWORD_FILE: /run/secrets/db_password }
    secrets: [db_password]
    volumes: ['pgdata:/var/lib/postgresql/data']
  redis:
    image: redis:7-alpine
    volumes: ['redisdata:/data']
volumes: { pgdata: {}, redisdata: {}, artifacts: {} }
secrets: { db_password: { file: ./secrets/db_password } }
```

- [ ] **Step 4: Lokal bauen und Smoke-Test**

```bash
docker build -f docker/Dockerfile -t kontorfix:dev .
docker run --rm kontorfix:dev php artisan --version   # bootet ohne Fehler
```

- [ ] **Step 5: Commit** — `build: docker image, compose stack and github actions ci`

---

## Self-Review (durchgeführt beim Schreiben)

1. **Spec-Coverage v0.1:** Kern-Datenmodell (T2–T3) ✓, Composer-Modul privat (T6–T11) ✓, Tokens (T4, T14) ✓, eine ACL-Schicht (T5) ✓, Admin-GUI-Basis inkl. flüssiger Zuweisung in beide Richtungen (T12–T13) ✓, UUID v7 (T2) ✓, Slug-Routing (T9) ✓, CI/Docker (T15) ✓. **Bewusst nicht in v0.1:** Multi-Domain-Routing (Tabelle existiert, Middleware v0.2), npm-Modul, Proxy, Webhooks, OIDC/Passkeys/TOTP, Kunden-Portal, S3-GUI — siehe Spec-Phasenplan.
2. **Platzhalter:** Keine TBD/TODO. GUI-Steps referenzieren bewusst Starter-Kit-Komponenten „beim Implementieren lokalisieren" — das ist Umgebungs-Discovery, der Ziel-Code ist angegeben.
3. **Typ-Konsistenz:** `RegistryToken::issue(Organization, string, ?Group, TokenAbility, ?DateTimeInterface)` überall gleich verwendet (T4, T5, T9-Helper, T14). `packagesFor(Group)`/`canAccessGroup(?RegistryToken, Group)` konsistent (T5, T9). `tokenHeaderFor()` wird in Task 10 nach `tests/Pest.php` gehoben — Tasks 9–11 nutzen dieselbe Signatur. `FixtureRepo::make()` (T6-Extraktion in T7 angekündigt) in T7, T10, T11 identisch.

# Kontorfix REST-API + API-Browser + Robot-Accounts — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein dritter Zugriffskanal — eine vollständige REST-Management-API unter `/api/v1` mit eigenem API-Key-Typ (`kfxapi_`, read/write), Robot-/Service-Accounts, einem auto-generierten interaktiven API-Browser und GUI-Verwaltung.

**Architecture:** Stateless `routes/api.php` (kein Cookie/CSRF), Bearer-Auth über neue `api_keys` (gebunden an einen Nutzer-/Robot-Account, erbt dessen Rolle & Org). Die Middleware `api.auth` setzt den Besitzer als „acting user", sodass die **bestehenden** `operator`/`role:…`-Gates und Policies unverändert greifen; zusätzlich deckelt ein read/write-Gate die Methoden. API-Controller sind dünn und **wiederverwenden die vorhandenen Admin-FormRequests** (die bei `Accept: application/json` automatisch JSON-Fehler liefern) sowie die vorhandene Modell-/Job-Logik → keine Divergenz GUI↔API. Antworten laufen über API-Resources (kein Secret-Leak). Doku via Scramble.

**Tech Stack:** Laravel 12, PHP 8.2+, UUID v7 (`HasUuids`), Postgres 17, Redis, Inertia v2 + Vue 3 + TS + Tailwind 3, `dedoc/scramble` (neu), Pest, Pint, Larastan L6, ESLint. DDEV (`ddev exec …`).

## Global Constraints

- **Sprache:** UI-Copy/Kommentare/Commit-Bodies auf **Deutsch**. Keine Tech-Stack-Begriffe in außenwirksamer Copy (API-/Entwickler-Doku darf alles benennen).
- **Commits:** Conventional Commits, Footer `Co-Authored-By: Claude <noreply@anthropic.com>`. **Lokal committen, kein `git push`** (Push macht der Haupt-Agent am Phasenende). Jeder Subagent verifiziert vor dem Commit `git symbolic-ref --short HEAD` == `main`.
- **Tests:** Neue Logik kommt mit Pest-Tests (TDD). Nach jeder Task: betroffene Tests grün, `ddev exec vendor/bin/pint`, `ddev exec vendor/bin/phpstan analyse`, bei JS zusätzlich `ddev exec npm run lint` + `ddev exec npm run build`.
- **Secrets:** Klartext-Keys nur einmalig in der Erstell-Response; Resources verstecken `key_hash`/`token_hash`/`password`.
- **Operator-Invariante (unverändert):** privilegierte Rollen (admin/maintainer) nur in der Betreiber-Org; gilt auch für Robots.
- **Key-Format:** API-Keys `kfxapi_` + 40 Zeichen; getrennt von Registry-Tokens `kfx_`.
- **Rate-Limit:** benannter Limiter `api`, 120 req/min pro Key (Fallback IP), `429` + `Retry-After`.

---

## File Structure

**Enums:** `app/Enums/AccountType.php`, `app/Enums/ApiKeyPermission.php`.
**Migrationen:** `…_add_account_type_to_users_table.php`, `…_make_users_password_nullable.php`, `…_create_api_keys_table.php`.
**Modelle:** `app/Models/ApiKey.php` (neu); `app/Models/User.php` (+ `account_type`-Cast, `isRobot()`, `apiKeys()`).
**Middleware:** `app/Http/Middleware/AuthenticateApiKey.php` (neu).
**Routing/Bootstrap:** `routes/api.php` (neu), `bootstrap/app.php` (api-Routing, `api.auth`-Alias), `app/Providers/AppServiceProvider.php` (RateLimiter `api`).
**API-Controller:** `app/Http/Controllers/Api/V1/{Me,ApiKey,Package,Group,GroupDomain,GroupUpstream,GroupPackage,RegistryToken,Webhook,Status,Organization,User}Controller.php`.
**API-Resources:** `app/Http/Resources/Api/{Me,ApiKey,Package,PackageVersion,Group,Domain,Upstream,RegistryToken,Webhook,Organization,User}Resource.php`.
**API-Requests:** `app/Http/Requests/Api/StoreApiKeyRequest.php` (neu; weitere Requests werden aus `App\Http\Requests\Admin\*` wiederverwendet).
**Auth-Härtung:** `app/Http/Controllers/Auth/{AuthenticatedSessionController,TwoFactorChallengeController,OidcController}.php` (Robot-Login-Sperre).
**Scramble:** `config/scramble.php` + `app/Providers/AppServiceProvider.php` (Gate).
**GUI:** `resources/js/pages/settings/ApiKeys.vue`, `app/Http/Controllers/Settings/ApiKeyController.php`, `resources/js/pages/admin/robots/Index.vue`, `app/Http/Controllers/Admin/RobotController.php`, `resources/js/layouts/settings/Layout.vue`, `resources/js/components/AppSidebar.vue`, `routes/settings.php`, `routes/web.php`.
**Tests:** `tests/Unit/ApiKeyIssueTest.php`, `tests/Feature/Api/*`, `tests/Feature/Auth/RobotLoginBlockedTest.php`, `tests/Feature/Settings/ApiKeyPageTest.php`, `tests/Feature/Admin/RobotManagementTest.php`.

---

## PHASE A — Auth-Fundament

### Task A1: Account-Typ `robot`

**Files:** Create `app/Enums/AccountType.php`, `database/migrations/2026_07_28_000001_add_account_type_to_users_table.php`, `database/migrations/2026_07_28_000002_make_users_password_nullable.php`; Modify `app/Models/User.php`, `database/factories/UserFactory.php`; Test `tests/Feature/Auth/AccountTypeTest.php`.

**Interfaces:**
- Produces: `App\Enums\AccountType` (Cases `Human='human'`, `Robot='robot'`); `User::isRobot(): bool`; `User` hat Cast `account_type => AccountType`; `UserFactory::robot(): static`.

- [ ] **Step 1: Failing test** `tests/Feature/Auth/AccountTypeTest.php`
```php
<?php

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults new users to human and casts the account type', function () {
    $user = User::factory()->create();

    expect($user->account_type)->toBe(AccountType::Human);
    expect($user->isRobot())->toBeFalse();
});

it('creates robot accounts without a password', function () {
    $robot = User::factory()->robot()->create();

    expect($robot->account_type)->toBe(AccountType::Robot);
    expect($robot->isRobot())->toBeTrue();
    expect($robot->password)->toBeNull();
});
```

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=AccountTypeTest`.

- [ ] **Step 3: Enum** `app/Enums/AccountType.php`
```php
<?php

namespace App\Enums;

enum AccountType: string
{
    case Human = 'human';
    case Robot = 'robot';
}
```

- [ ] **Step 4: Migration** `database/migrations/2026_07_28_000001_add_account_type_to_users_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_type')->default('human')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }
};
```

- [ ] **Step 5: Migration** `database/migrations/2026_07_28_000002_make_users_password_nullable.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
```
Falls `->change()` an einem `doctrine/dbal`-Fehler scheitert: In Laravel 12 ist `change()` nativ; kein dbal nötig. Sollte dennoch ein Problem auftreten, im Bericht vermerken.

- [ ] **Step 6: Model** `app/Models/User.php`:
  - Import `use App\Enums\AccountType;`.
  - `'account_type'` in `$fillable` (nach `'role'`).
  - Im `casts()`-Array `'account_type' => AccountType::class,` ergänzen.
  - Methode ergänzen:
```php
public function isRobot(): bool
{
    return $this->account_type === AccountType::Robot;
}
```

- [ ] **Step 7: Factory** `database/factories/UserFactory.php` — State ergänzen:
```php
public function robot(): static
{
    return $this->state(fn () => [
        'account_type' => \App\Enums\AccountType::Robot,
        'password' => null,
    ]);
}
```

- [ ] **Step 8: Run → PASS** `ddev exec vendor/bin/pest --filter=AccountTypeTest`. Pint + PHPStan grün.
- [ ] **Step 9: Commit** `feat: add robot account type to users`.

---

### Task A2: `api_keys`-Modell

**Files:** Create `app/Enums/ApiKeyPermission.php`, `database/migrations/2026_07_28_000003_create_api_keys_table.php`, `app/Models/ApiKey.php`, `database/factories/ApiKeyFactory.php`; Modify `app/Models/User.php`; Test `tests/Unit/ApiKeyIssueTest.php`.

**Interfaces:**
- Produces: `App\Enums\ApiKeyPermission` (`Read='read'`, `Write='write'`); `ApiKey::issue(User $owner, string $name, ApiKeyPermission $permission, ?\DateTimeInterface $expiresAt = null): array{0: ApiKey, 1: string}`; `ApiKey::findByPlainText(string $plain): ?ApiKey`; `ApiKey` Properties `user_id,name,key_hash,permission,last_used_at,expires_at`; `User::apiKeys(): HasMany`.

- [ ] **Step 1: Failing test** `tests/Unit/ApiKeyIssueTest.php`
```php
<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('issues a hashed api key and returns the plaintext once', function () {
    $user = User::factory()->create();

    [$key, $plain] = ApiKey::issue($user, 'ci', ApiKeyPermission::Write);

    expect($plain)->toStartWith('kfxapi_');
    expect($key->user_id)->toBe($user->id);
    expect($key->permission)->toBe(ApiKeyPermission::Write);
    expect($key->key_hash)->toBe(hash('sha256', $plain));
    expect($key->getAttributes())->not->toHaveKey('plain');
});

it('finds a key by plaintext and ignores expired ones', function () {
    $user = User::factory()->create();
    [, $plain] = ApiKey::issue($user, 'valid', ApiKeyPermission::Read);
    [$expired, $expiredPlain] = ApiKey::issue($user, 'old', ApiKeyPermission::Read, now()->subDay());

    expect(ApiKey::findByPlainText($plain)?->name)->toBe('valid');
    expect(ApiKey::findByPlainText($expiredPlain))->toBeNull();
    expect(ApiKey::findByPlainText('kfxapi_nonexistent'))->toBeNull();
});
```

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=ApiKeyIssueTest`.

- [ ] **Step 3: Enum** `app/Enums/ApiKeyPermission.php`
```php
<?php

namespace App\Enums;

enum ApiKeyPermission: string
{
    case Read = 'read';
    case Write = 'write';
}
```

- [ ] **Step 4: Migration** `database/migrations/2026_07_28_000003_create_api_keys_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key_hash')->unique();
            $table->string('permission')->default('read');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
```

- [ ] **Step 5: Model** `app/Models/ApiKey.php`
```php
<?php

namespace App\Models;

use App\Enums\ApiKeyPermission;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 */
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'name',
        'key_hash',
        'permission',
        'last_used_at',
        'expires_at',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected function casts(): array
    {
        return [
            'permission' => ApiKeyPermission::class,
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{0: self, 1: string}
     */
    public static function issue(User $owner, string $name, ApiKeyPermission $permission = ApiKeyPermission::Read, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = 'kfxapi_'.Str::random(40);
        $key = static::create([
            'user_id' => $owner->id,
            'name' => $name,
            'key_hash' => hash('sha256', $plain),
            'permission' => $permission,
            'expires_at' => $expiresAt,
        ]);

        return [$key, $plain];
    }

    public static function findByPlainText(string $plain): ?self
    {
        return static::query()
            ->where('key_hash', hash('sha256', $plain))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }
}
```

- [ ] **Step 6: Factory** `database/factories/ApiKeyFactory.php`
```php
<?php

namespace Database\Factories;

use App\Enums\ApiKeyPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\ApiKey>
 */
class ApiKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'key_hash' => hash('sha256', 'kfxapi_'.Str::random(40)),
            'permission' => ApiKeyPermission::Read,
        ];
    }

    public function write(): static
    {
        return $this->state(fn () => ['permission' => ApiKeyPermission::Write]);
    }
}
```

- [ ] **Step 7: Relation** in `app/Models/User.php` ergänzen:
```php
/**
 * @return \Illuminate\Database\Eloquent\Relations\HasMany<ApiKey, $this>
 */
public function apiKeys(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(ApiKey::class);
}
```

- [ ] **Step 8: Run → PASS** `ddev exec vendor/bin/pest --filter=ApiKeyIssueTest`. Pint + PHPStan grün.
- [ ] **Step 9: Commit** `feat: add api key model with hashed issuance`.

---

### Task A3: `api.auth`-Middleware + Routing + RateLimiter + `me`-Endpunkt

**Files:** Create `app/Http/Middleware/AuthenticateApiKey.php`, `routes/api.php`, `app/Http/Controllers/Api/V1/MeController.php`, `app/Http/Resources/Api/MeResource.php`; Modify `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`; Test `tests/Feature/Api/ApiKeyAuthTest.php`.

**Interfaces:**
- Consumes: `ApiKey::findByPlainText()`, `ApiKeyPermission`.
- Produces: Middleware-Alias `api.auth`; `/api/v1/me` (GET) → `MeResource`; alle künftigen `/api/v1`-Routen laufen durch `api.auth` (setzt Besitzer als `$request->user()`) + read/write-Gate + `throttle:api`.

- [ ] **Step 1: Failing test** `tests/Feature/Api/ApiKeyAuthTest.php`
```php
<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects requests without a valid bearer key', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->withToken('kfxapi_invalid')->getJson('/api/v1/me')->assertUnauthorized();
});

it('authenticates as the key owner and returns the profile', function () {
    $user = User::factory()->create(['name' => 'Ada']);
    [, $plain] = ApiKey::issue($user, 'ci', ApiKeyPermission::Read);

    $this->withToken($plain)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.name', 'Ada')
        ->assertJsonPath('data.account_type', 'human');
});

it('updates last_used_at on use', function () {
    $user = User::factory()->create();
    [$key, $plain] = ApiKey::issue($user, 'ci', ApiKeyPermission::Read);
    expect($key->last_used_at)->toBeNull();

    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    expect($key->fresh()->last_used_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=ApiKeyAuthTest` (Route/Alias fehlt).

- [ ] **Step 3: Middleware** `app/Http/Middleware/AuthenticateApiKey.php`
```php
<?php

namespace App\Http\Middleware;

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $key = $plain ? ApiKey::findByPlainText($plain) : null;

        if ($key === null || $key->user === null) {
            return response()->json(['message' => 'Ungültiger oder fehlender API-Key.'], 401);
        }

        // read-Keys dürfen ausschließlich lesen.
        if ($key->permission === ApiKeyPermission::Read
            && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return response()->json(['message' => 'Dieser API-Key hat nur Leserechte.'], 403);
        }

        // Besitzer als authentifizierten Nutzer setzen → operator/role-Gates + Policies greifen.
        Auth::setUser($key->user);
        $request->setUserResolver(fn () => $key->user);
        $request->attributes->set('apiKey', $key);

        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: RateLimiter** in `app/Providers/AppServiceProvider.php`, Methode `boot()`, ergänzen (Imports `use Illuminate\Cache\RateLimiting\Limit;`, `use Illuminate\Support\Facades\RateLimiter;`, `use Illuminate\Http\Request;`):
```php
RateLimiter::for('api', function (Request $request) {
    $key = $request->attributes->get('apiKey');
    $id = $key?->getKey() ?? $request->ip();

    return Limit::perMinute(120)->by('api:'.$id);
});
```

- [ ] **Step 5: Routing** in `bootstrap/app.php`:
  - Import `use App\Http\Middleware\AuthenticateApiKey;`.
  - `withRouting(...)` um den `api:`-Parameter erweitern (neben `web:`):
    ```php
    api: __DIR__.'/../routes/api.php',
    apiPrefix: 'api',
    ```
  - Im `withMiddleware`-`alias`-Array ergänzen: `'api.auth' => AuthenticateApiKey::class,`.

- [ ] **Step 6: Routen** `routes/api.php`
```php
<?php

use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

// Alle Management-Endpunkte sind stateless (Bearer-Key), versioniert unter /api/v1.
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['throttle:api', 'api.auth'])
    ->group(function () {
        Route::get('me', [MeController::class, 'show'])->name('me');
    });
```

- [ ] **Step 7: Resource** `app/Http/Resources/Api/MeResource.php`
```php
<?php

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class MeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'account_type' => $this->account_type->value,
            'organization' => $this->organization?->only(['id', 'name']),
        ];
    }
}
```

- [ ] **Step 8: Controller** `app/Http/Controllers/Api/V1/MeController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\MeResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): MeResource
    {
        return new MeResource($request->user()->loadMissing('organization'));
    }
}
```

- [ ] **Step 9: Run → PASS** `ddev exec vendor/bin/pest --filter=ApiKeyAuthTest`. Pint + PHPStan grün. Prüfe `ddev exec php artisan route:list --path=api/v1` zeigt `api/v1/me`.
- [ ] **Step 10: Commit** `feat: bearer api key auth, versioned api routing and me endpoint`.

---

### Task A4: Robot-Accounts vom interaktiven Login ausschließen

**Files:** Modify `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Controllers/Auth/TwoFactorChallengeController.php`, `app/Http/Controllers/Auth/OidcController.php`; Test `tests/Feature/Auth/RobotLoginBlockedTest.php`.

**Interfaces:**
- Consumes: `User::isRobot()`.

- [ ] **Step 1: Failing test** `tests/Feature/Auth/RobotLoginBlockedTest.php`
```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('blocks robot accounts from interactive password login', function () {
    // Robot mit gesetztem Passwort (Kante): darf sich trotzdem nicht interaktiv anmelden.
    $robot = User::factory()->robot()->create([
        'email' => 'bot@example.test',
        'password' => Hash::make('secret-password'),
    ]);

    $this->post('/login', ['email' => 'bot@example.test', 'password' => 'secret-password'])
        ->assertForbidden();

    $this->assertGuest();
});
```

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=RobotLoginBlockedTest`.

- [ ] **Step 3:** In `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, Methode `store()`, **direkt vor** dem `Auth::login($user, …)`-Aufruf einfügen (der authentifizierte Nutzer liegt nach `$request->authenticate()` an `Auth::user()` bzw. wird per `$user` referenziert — nimm die im Controller vorhandene `$user`-Variable; falls keine existiert, `$user = $request->user();` unmittelbar nach `authenticate()` ableiten):
```php
abort_if($user->isRobot(), 403, 'Robot-Accounts können sich nicht interaktiv anmelden.');
```
  Lies die Methode zuerst und platziere den Guard so, dass er greift, bevor eine Session etabliert wird. Falls `authenticate()` bereits einloggt, den Guard auf `Auth::user()` vor dem Redirect setzen und bei Robot `Auth::logout()` + `abort(403, …)`.

- [ ] **Step 4:** In `app/Http/Controllers/Auth/TwoFactorChallengeController.php` **vor** `Auth::login($user, $remember);` einfügen:
```php
abort_if($user->isRobot(), 403, 'Robot-Accounts können sich nicht interaktiv anmelden.');
```

- [ ] **Step 5:** In `app/Http/Controllers/Auth/OidcController.php`, Methode `callback()`, **vor** `Auth::login($user);` einfügen:
```php
abort_if($user->isRobot(), 403, 'Robot-Accounts können sich nicht interaktiv anmelden.');
```

- [ ] **Step 6: Run → PASS** `ddev exec vendor/bin/pest --filter=RobotLoginBlockedTest`. Volle Auth-Test-Gruppe grün: `ddev exec vendor/bin/pest tests/Feature/Auth`. Pint + PHPStan grün.
- [ ] **Step 7: Commit** `feat: block robot accounts from interactive login`.

---

## PHASE B — Self-Service & Ressourcen

> **Muster für alle Ressourcen-Tasks:** dünner Controller unter `App\Http\Controllers\Api\V1`, Antworten via `App\Http\Resources\Api\*Resource`, Validierung durch **Wiederverwendung** der vorhandenen `App\Http\Requests\Admin\*`-Requests (JSON-Fehler kommen automatisch bei `Accept: application/json`). Rollen-Gates in `routes/api.php` per `middleware('operator')` / `middleware('role:admin,maintainer')` **exakt wie die GUI-Route**. Tests nutzen `->withToken($plain)` + `->getJson/postJson/deleteJson`.

### Task B1: Selbstverwaltung eigener API-Keys (`me/api-keys`)

**Files:** Create `app/Http/Requests/Api/StoreApiKeyRequest.php`, `app/Http/Resources/Api/ApiKeyResource.php`, `app/Http/Controllers/Api/V1/ApiKeyController.php`; Modify `routes/api.php`; Test `tests/Feature/Api/MeApiKeyTest.php`.

**Interfaces:**
- Consumes: `ApiKey::issue()`, `ApiKeyPermission`.
- Produces: `GET/POST /api/v1/me/api-keys`, `DELETE /api/v1/me/api-keys/{apiKey}`; `ApiKeyResource`; `StoreApiKeyRequest` (Felder `name`, `permission`, `expires_at?`).

- [ ] **Step 1: Failing test** `tests/Feature/Api/MeApiKeyTest.php`
```php
<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists only the owners keys', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    ApiKey::issue($me, 'mine', ApiKeyPermission::Read);
    ApiKey::issue($other, 'theirs', ApiKeyPermission::Read);
    [, $plain] = ApiKey::issue($me, 'auth', ApiKeyPermission::Read);

    $this->withToken($plain)->getJson('/api/v1/me/api-keys')
        ->assertOk()
        ->assertJsonCount(2, 'data'); // mine + auth, nicht theirs
});

it('read keys cannot create, write keys can', function () {
    $me = User::factory()->create();
    [, $readPlain] = ApiKey::issue($me, 'r', ApiKeyPermission::Read);
    [, $writePlain] = ApiKey::issue($me, 'w', ApiKeyPermission::Write);

    $this->withToken($readPlain)->postJson('/api/v1/me/api-keys', ['name' => 'x', 'permission' => 'read'])
        ->assertForbidden();

    $this->withToken($writePlain)->postJson('/api/v1/me/api-keys', ['name' => 'deploy', 'permission' => 'write'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'deploy')
        ->assertJsonPath('data.plain_text', fn ($v) => str_starts_with($v, 'kfxapi_'));
});

it('forbids deleting a foreign key', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    [$foreign] = ApiKey::issue($other, 'theirs', ApiKeyPermission::Read);
    [, $writePlain] = ApiKey::issue($me, 'w', ApiKeyPermission::Write);

    $this->withToken($writePlain)->deleteJson("/api/v1/me/api-keys/{$foreign->id}")->assertForbidden();
    expect(ApiKey::find($foreign->id))->not->toBeNull();
});
```

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=MeApiKeyTest`.

- [ ] **Step 3: Request** `app/Http/Requests/Api/StoreApiKeyRequest.php`
```php
<?php

namespace App\Http\Requests\Api;

use App\Enums\ApiKeyPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'permission' => ['required', Rule::enum(ApiKeyPermission::class)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
```

- [ ] **Step 4: Resource** `app/Http/Resources/Api/ApiKeyResource.php`
```php
<?php

namespace App\Http\Resources\Api;

use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApiKey */
class ApiKeyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'permission' => $this->permission->value,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Klartext NUR direkt nach Erstellung (per ->additional() gesetzt).
            'plain_text' => $this->when(isset($this->plain_text), fn () => $this->plain_text),
        ];
    }
}
```

- [ ] **Step 5: Controller** `app/Http/Controllers/Api/V1/ApiKeyController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiKeyPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreApiKeyRequest;
use App\Http\Resources\Api\ApiKeyResource;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApiKeyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ApiKeyResource::collection(
            $request->user()->apiKeys()->latest()->get()
        );
    }

    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        [$key, $plain] = ApiKey::issue(
            $request->user(),
            $request->validated('name'),
            $request->enum('permission', ApiKeyPermission::class),
            $request->date('expires_at'),
        );

        $key->plain_text = $plain;

        return (new ApiKeyResource($key))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, ApiKey $apiKey): JsonResponse
    {
        abort_unless($apiKey->user_id === $request->user()->id, 403);
        $apiKey->delete();

        return response()->json(status: 204);
    }
}
```

- [ ] **Step 6: Routen** in `routes/api.php` innerhalb der `v1`-Gruppe ergänzen (Import `use App\Http\Controllers\Api\V1\ApiKeyController;`):
```php
Route::get('me/api-keys', [ApiKeyController::class, 'index'])->name('me.api-keys.index');
Route::post('me/api-keys', [ApiKeyController::class, 'store'])->name('me.api-keys.store');
Route::delete('me/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('me.api-keys.destroy');
```

- [ ] **Step 7: Run → PASS** `ddev exec vendor/bin/pest --filter=MeApiKeyTest`. Pint + PHPStan grün.
- [ ] **Step 8: Commit** `feat: self-service api key management endpoints`.

---

### Task B2: Pakete-API

**Files:** Create `app/Http/Resources/Api/{Package,PackageVersion}Resource.php`, `app/Http/Controllers/Api/V1/PackageController.php`; Modify `routes/api.php`; Test `tests/Feature/Api/PackageApiTest.php`.

**Interfaces:**
- Consumes: `StorePackageRequest` (Admin), `SyncPackage`-Job, `Package`-Model.
- Produces: `GET /api/v1/packages`, `GET /api/v1/packages/{package}`, `POST /api/v1/packages`, `DELETE /api/v1/packages/{package}`, `POST /api/v1/packages/{package}/resync`; `PackageResource`.

- [ ] **Step 1: Failing test** `tests/Feature/Api/PackageApiTest.php`
```php
<?php

use App\Jobs\SyncPackage;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function operatorWriteToken(): string
{
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $org->id, 'role' => 'admin']);
    [, $plain] = ApiKey::issue($admin, 'w', \App\Enums\ApiKeyPermission::Write);

    return $plain;
}

it('lists and shows packages', function () {
    $plain = operatorWriteToken();
    $package = Package::factory()->create(['name' => 'acme/widget']);

    $this->withToken($plain)->getJson('/api/v1/packages')
        ->assertOk()->assertJsonPath('data.0.name', 'acme/widget');

    $this->withToken($plain)->getJson("/api/v1/packages/{$package->id}")
        ->assertOk()->assertJsonPath('data.name', 'acme/widget');
});

it('creates a package and dispatches a sync', function () {
    Queue::fake();
    $plain = operatorWriteToken();

    $this->withToken($plain)->postJson('/api/v1/packages', [
        'type' => 'composer',
        'name' => 'acme/new',
        'repository_url' => 'https://github.com/acme/new.git',
    ])->assertCreated()->assertJsonPath('data.name', 'acme/new');

    Queue::assertPushed(SyncPackage::class);
});

it('triggers a resync', function () {
    Queue::fake();
    $plain = operatorWriteToken();
    $package = Package::factory()->create(['repository_url' => 'https://github.com/acme/w.git']);

    $this->withToken($plain)->postJson("/api/v1/packages/{$package->id}/resync")->assertOk();
    Queue::assertPushed(SyncPackage::class);
});

it('denies members without operator role', function () {
    $org = Organization::factory()->create(['is_operator' => false]);
    $member = User::factory()->create(['organization_id' => $org->id, 'role' => 'member']);
    [, $plain] = ApiKey::issue($member, 'w', \App\Enums\ApiKeyPermission::Write);

    $this->withToken($plain)->getJson('/api/v1/packages')->assertForbidden();
});
```
Prüfe die `PackageFactory` auf Pflichtfelder; ergänze im Test nötige Attribute (`type`, `sync_status`) falls die Factory sie nicht setzt.

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=PackageApiTest`.

- [ ] **Step 3: Resources**
`app/Http/Resources/Api/PackageResource.php`
```php
<?php

namespace App\Http\Resources\Api;

use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Package */
class PackageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'description' => $this->description,
            'repository_url' => $this->repository_url,
            'sync_status' => $this->sync_status->value,
            'sync_error' => $this->sync_error,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'versions' => PackageVersionResource::collection($this->whenLoaded('versions')),
        ];
    }
}
```
`app/Http/Resources/Api/PackageVersionResource.php`
```php
<?php

namespace App\Http\Resources\Api;

use App\Models\PackageVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PackageVersion */
class PackageVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->version_pretty ?? $this->version,
            'released_at' => $this->released_at?->toIso8601String(),
            'reference' => $this->source_reference,
        ];
    }
}
```

- [ ] **Step 4: Controller** `app/Http/Controllers/Api/V1/PackageController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Http\Resources\Api\PackageResource;
use App\Jobs\SyncPackage;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PackageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = trim((string) $request->query('q', ''));
        $type = $request->query('type');

        $packages = Package::query()
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->when(in_array($type, ['composer', 'npm'], true), fn ($query) => $query->where('type', $type))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100))
            ->withQueryString();

        return PackageResource::collection($packages);
    }

    public function show(Package $package): PackageResource
    {
        return new PackageResource($package->load('versions'));
    }

    public function store(StorePackageRequest $request): JsonResponse
    {
        $package = Package::create($request->safe()->except('group_ids'));
        $package->groups()->sync($request->validated('group_ids', []));
        SyncPackage::dispatch($package);

        return (new PackageResource($package))->response()->setStatusCode(201);
    }

    public function resync(Package $package): PackageResource
    {
        SyncPackage::dispatch($package);

        return new PackageResource($package);
    }

    public function destroy(Package $package): JsonResponse
    {
        $package->delete();

        return response()->json(status: 204);
    }
}
```

- [ ] **Step 5: Routen** in `routes/api.php` — neue Gruppe mit Operator-Gate (Import `use App\Http\Controllers\Api\V1\PackageController;`):
```php
Route::middleware(['operator', 'role:admin,maintainer'])->group(function () {
    Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
    Route::post('packages', [PackageController::class, 'store'])->name('packages.store');
    Route::get('packages/{package}', [PackageController::class, 'show'])->name('packages.show');
    Route::post('packages/{package}/resync', [PackageController::class, 'resync'])->name('packages.resync');
    Route::delete('packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');
});
```

- [ ] **Step 6: Run → PASS** `ddev exec vendor/bin/pest --filter=PackageApiTest`. Pint + PHPStan grün.
- [ ] **Step 7: Commit** `feat: packages rest api`.

---

### Task B3: Registries/Gruppen-API (CRUD)

**Files:** Create `app/Http/Resources/Api/GroupResource.php`, `app/Http/Controllers/Api/V1/GroupController.php`; Modify `routes/api.php`; Test `tests/Feature/Api/GroupApiTest.php`.

**Interfaces:**
- Consumes: `StoreGroupRequest`, `UpdateGroupRequest` (Admin), `Group`-Model.
- Produces: `GET /api/v1/groups`, `GET /api/v1/groups/{group}`, `POST /api/v1/groups`, `PUT /api/v1/groups/{group}`, `DELETE /api/v1/groups/{group}`; `GroupResource`.

- [ ] **Step 1: Failing test** `tests/Feature/Api/GroupApiTest.php`
```php
<?php

use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin']);
    [, $this->plain] = ApiKey::issue($this->admin, 'w', \App\Enums\ApiKeyPermission::Write);
});

it('creates, updates and lists registries', function () {
    $this->withToken($this->plain)->postJson('/api/v1/groups', [
        'name' => 'Acme',
        'slug' => 'acme',
        'public' => false,
        'organization_id' => $this->org->id,
    ])->assertCreated()->assertJsonPath('data.slug', 'acme');

    $group = Group::firstWhere('slug', 'acme');

    $this->withToken($this->plain)->putJson("/api/v1/groups/{$group->id}", [
        'name' => 'Acme Corp', 'public' => true,
    ])->assertOk()->assertJsonPath('data.name', 'Acme Corp');

    $this->withToken($this->plain)->getJson('/api/v1/groups')
        ->assertOk()->assertJsonPath('data.0.name', 'Acme Corp');
});

it('deletes a registry', function () {
    $group = Group::factory()->create(['organization_id' => $this->org->id]);
    $this->withToken($this->plain)->deleteJson("/api/v1/groups/{$group->id}")->assertNoContent();
    expect(Group::find($group->id))->toBeNull();
});
```

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=GroupApiTest`.

- [ ] **Step 3: Resource** `app/Http/Resources/Api/GroupResource.php`
```php
<?php

namespace App\Http\Resources\Api;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Group */
class GroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'public' => $this->public,
            'organization_id' => $this->organization_id,
        ];
    }
}
```

- [ ] **Step 4: Controller** `app/Http/Controllers/Api/V1/GroupController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGroupRequest;
use App\Http\Requests\Admin\UpdateGroupRequest;
use App\Http\Resources\Api\GroupResource;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return GroupResource::collection(
            Group::orderBy('name')->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function show(Group $group): GroupResource
    {
        return new GroupResource($group);
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $group = Group::create([
            'name' => $request->validated('name'),
            'slug' => $request->validated('slug'),
            'public' => $request->boolean('public'),
            'organization_id' => $request->validated('organization_id') ?? $request->user()->organization_id,
        ]);
        $group->packages()->sync($request->validated('package_ids', []));

        return (new GroupResource($group))->response()->setStatusCode(201);
    }

    public function update(UpdateGroupRequest $request, Group $group): GroupResource
    {
        $group->update(['name' => $request->validated('name'), 'public' => $request->boolean('public')]);

        return new GroupResource($group);
    }

    public function destroy(Group $group): JsonResponse
    {
        $group->delete();

        return response()->json(status: 204);
    }
}
```

- [ ] **Step 5: Routen** in der Operator-Gruppe von `routes/api.php` ergänzen (Import `use App\Http\Controllers\Api\V1\GroupController;`):
```php
Route::get('groups', [GroupController::class, 'index'])->name('groups.index');
Route::post('groups', [GroupController::class, 'store'])->name('groups.store');
Route::get('groups/{group}', [GroupController::class, 'show'])->name('groups.show');
Route::put('groups/{group}', [GroupController::class, 'update'])->name('groups.update');
Route::delete('groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');
```

- [ ] **Step 6: Run → PASS** `ddev exec vendor/bin/pest --filter=GroupApiTest`. Pint + PHPStan grün.
- [ ] **Step 7: Commit** `feat: registries rest api`.

---

### Task B4: Gruppen-Unterressourcen (Domains, Upstreams, Paket-Zuordnung)

**Files:** Create `app/Http/Resources/Api/{Domain,Upstream}Resource.php`, `app/Http/Controllers/Api/V1/{GroupDomain,GroupUpstream,GroupPackage}Controller.php`; Modify `routes/api.php`; Test `tests/Feature/Api/GroupSubresourceApiTest.php`.

**Interfaces:**
- Consumes: `StoreDomainRequest`, `StoreUpstreamRequest` (Admin), `Domain`/`Upstream`/`Group`-Modelle, `PackageResource`.
- Produces: `GET/POST /api/v1/groups/{group}/domains`, `DELETE …/domains/{domain}`; `GET/POST …/upstreams`, `DELETE …/upstreams/{upstream}`; `GET …/packages`, `PUT …/packages`.

- [ ] **Step 1: Failing test** `tests/Feature/Api/GroupSubresourceApiTest.php`
```php
<?php

use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin']);
    [, $this->plain] = ApiKey::issue($this->admin, 'w', \App\Enums\ApiKeyPermission::Write);
    $this->group = Group::factory()->create(['organization_id' => $this->org->id]);
});

it('adds and removes a domain', function () {
    $res = $this->withToken($this->plain)->postJson("/api/v1/groups/{$this->group->id}/domains", [
        'group_id' => $this->group->id,
        'hostname' => 'packages.acme.test',
    ])->assertCreated()->assertJsonPath('data.hostname', 'packages.acme.test');

    $id = $res->json('data.id');
    $this->withToken($this->plain)->deleteJson("/api/v1/groups/{$this->group->id}/domains/{$id}")->assertNoContent();
});

it('sets the package assignment', function () {
    $a = Package::factory()->create();
    $b = Package::factory()->create();

    $this->withToken($this->plain)->putJson("/api/v1/groups/{$this->group->id}/packages", [
        'package_ids' => [$a->id, $b->id],
    ])->assertOk()->assertJsonCount(2, 'data');

    expect($this->group->fresh()->packages()->count())->toBe(2);
});
```
Prüfe die Felder von `StoreDomainRequest`/`StoreUpstreamRequest` und `Domain`/`Upstream`-Fillables und passe die Payloads an (Domain: `group_id`,`hostname`; Upstream: `group_id`,`type`,`url`,`policy`).

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=GroupSubresourceApiTest`.

- [ ] **Step 3: Resources**
`app/Http/Resources/Api/DomainResource.php`
```php
<?php

namespace App\Http\Resources\Api;

use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Domain */
class DomainResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'hostname' => $this->hostname, 'group_id' => $this->group_id];
    }
}
```
`app/Http/Resources/Api/UpstreamResource.php`
```php
<?php

namespace App\Http\Resources\Api;

use App\Models\Upstream;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Upstream */
class UpstreamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'type' => $this->type->value,
            'url' => $this->url,
            'policy' => $this->policy->value,
        ];
    }
}
```

- [ ] **Step 4: Controller** `app/Http/Controllers/Api/V1/GroupDomainController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDomainRequest;
use App\Http\Resources\Api\DomainResource;
use App\Models\Domain;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupDomainController extends Controller
{
    public function index(Group $group): AnonymousResourceCollection
    {
        return DomainResource::collection($group->domains);
    }

    public function store(StoreDomainRequest $request, Group $group): JsonResponse
    {
        $domain = $group->domains()->create(['hostname' => $request->validated('hostname')]);

        return (new DomainResource($domain))->response()->setStatusCode(201);
    }

    public function destroy(Group $group, Domain $domain): JsonResponse
    {
        abort_unless($domain->group_id === $group->id, 404);
        $domain->delete();

        return response()->json(status: 204);
    }
}
```

- [ ] **Step 5: Controller** `app/Http/Controllers/Api/V1/GroupUpstreamController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUpstreamRequest;
use App\Http\Resources\Api\UpstreamResource;
use App\Models\Group;
use App\Models\Upstream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupUpstreamController extends Controller
{
    public function index(Group $group): AnonymousResourceCollection
    {
        return UpstreamResource::collection($group->upstreams);
    }

    public function store(StoreUpstreamRequest $request, Group $group): JsonResponse
    {
        $upstream = $group->upstreams()->create([
            'type' => $request->validated('type'),
            'url' => $request->validated('url'),
            'policy' => $request->validated('policy'),
        ]);

        return (new UpstreamResource($upstream))->response()->setStatusCode(201);
    }

    public function destroy(Group $group, Upstream $upstream): JsonResponse
    {
        abort_unless($upstream->group_id === $group->id, 404);
        $upstream->delete();

        return response()->json(status: 204);
    }
}
```
Prüfe, ob `Group::domains()`/`upstreams()` `create([...])` mit diesen Feldern erlauben (Fillable). Falls die Modelle `hostname`/`type`/`url`/`policy` nicht als fillable führen, nutze `new Domain([...])` + `->group()->associate()` bzw. ergänze `$fillable` — im Bericht vermerken.

- [ ] **Step 6: Controller** `app/Http/Controllers/Api/V1/GroupPackageController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PackageResource;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class GroupPackageController extends Controller
{
    public function index(Group $group): AnonymousResourceCollection
    {
        return PackageResource::collection($group->packages()->orderBy('name')->get());
    }

    public function update(Request $request, Group $group): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'package_ids' => ['array'],
            'package_ids.*' => ['uuid', Rule::exists('packages', 'id')],
        ]);

        $group->packages()->sync($validated['package_ids'] ?? []);

        return PackageResource::collection($group->packages()->orderBy('name')->get());
    }
}
```

- [ ] **Step 7: Routen** in der Operator-Gruppe von `routes/api.php` ergänzen (Imports der drei Controller):
```php
Route::get('groups/{group}/domains', [GroupDomainController::class, 'index'])->name('groups.domains.index');
Route::post('groups/{group}/domains', [GroupDomainController::class, 'store'])->name('groups.domains.store');
Route::delete('groups/{group}/domains/{domain}', [GroupDomainController::class, 'destroy'])->name('groups.domains.destroy');

Route::get('groups/{group}/upstreams', [GroupUpstreamController::class, 'index'])->name('groups.upstreams.index');
Route::post('groups/{group}/upstreams', [GroupUpstreamController::class, 'store'])->name('groups.upstreams.store');
Route::delete('groups/{group}/upstreams/{upstream}', [GroupUpstreamController::class, 'destroy'])->name('groups.upstreams.destroy');

Route::get('groups/{group}/packages', [GroupPackageController::class, 'index'])->name('groups.packages.index');
Route::put('groups/{group}/packages', [GroupPackageController::class, 'update'])->name('groups.packages.update');
```

- [ ] **Step 8: Run → PASS** `ddev exec vendor/bin/pest --filter=GroupSubresourceApiTest`. Pint + PHPStan grün.
- [ ] **Step 9: Commit** `feat: registry sub-resource rest api (domains, upstreams, package assignment)`.

---

### Task B5: Registry-Tokens-API

**Files:** Create `app/Http/Resources/Api/RegistryTokenResource.php`, `app/Http/Controllers/Api/V1/RegistryTokenController.php`; Modify `routes/api.php`; Test `tests/Feature/Api/RegistryTokenApiTest.php`.

**Interfaces:**
- Consumes: `StoreTokenRequest` (Admin), `RegistryToken::issue()`, `TokenAbility`.
- Produces: `GET/POST /api/v1/registry-tokens`, `DELETE …/{token}`; `RegistryTokenResource` (Klartext nur bei Erstellung als `plain_text`).

- [ ] **Step 1: Failing test** `tests/Feature/Api/RegistryTokenApiTest.php`
```php
<?php

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin']);
    [, $this->plain] = ApiKey::issue($this->admin, 'w', \App\Enums\ApiKeyPermission::Write);
});

it('issues a registry token and returns the plaintext once', function () {
    $this->withToken($this->plain)->postJson('/api/v1/registry-tokens', [
        'name' => 'ci-pull',
        'organization_id' => $this->org->id,
        'ability' => 'read',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'ci-pull')
        ->assertJsonPath('data.plain_text', fn ($v) => str_starts_with($v, 'kfx_'));
});

it('lists and revokes registry tokens', function () {
    [$token] = RegistryToken::issue($this->org, 'old', null);
    $this->withToken($this->plain)->getJson('/api/v1/registry-tokens')->assertOk();
    $this->withToken($this->plain)->deleteJson("/api/v1/registry-tokens/{$token->id}")->assertNoContent();
    expect(RegistryToken::find($token->id))->toBeNull();
});
```

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=RegistryTokenApiTest`.

- [ ] **Step 3: Resource** `app/Http/Resources/Api/RegistryTokenResource.php`
```php
<?php

namespace App\Http\Resources\Api;

use App\Models\RegistryToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RegistryToken */
class RegistryTokenResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ability' => $this->ability->value,
            'organization_id' => $this->organization_id,
            'group_id' => $this->group_id,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'plain_text' => $this->when(isset($this->plain_text), fn () => $this->plain_text),
        ];
    }
}
```

- [ ] **Step 4: Controller** `app/Http/Controllers/Api/V1/RegistryTokenController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTokenRequest;
use App\Http\Resources\Api\RegistryTokenResource;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RegistryTokenController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RegistryTokenResource::collection(
            RegistryToken::with(['organization:id,name', 'group:id,name'])->latest()->paginate(
                min((int) $request->query('per_page', 25), 100)
            )
        );
    }

    public function store(StoreTokenRequest $request): JsonResponse
    {
        [$token, $plain] = RegistryToken::issue(
            Organization::findOrFail($request->validated('organization_id')),
            $request->validated('name'),
            $request->validated('group_id') ? Group::findOrFail($request->validated('group_id')) : null,
            $request->enum('ability', TokenAbility::class) ?? TokenAbility::Read,
        );

        $token->plain_text = $plain;

        return (new RegistryTokenResource($token))->response()->setStatusCode(201);
    }

    public function destroy(RegistryToken $registryToken): JsonResponse
    {
        $registryToken->delete();

        return response()->json(status: 204);
    }
}
```

- [ ] **Step 5: Routen** in der Operator-Gruppe (Import `RegistryTokenController`):
```php
Route::get('registry-tokens', [RegistryTokenController::class, 'index'])->name('registry-tokens.index');
Route::post('registry-tokens', [RegistryTokenController::class, 'store'])->name('registry-tokens.store');
Route::delete('registry-tokens/{registryToken}', [RegistryTokenController::class, 'destroy'])->name('registry-tokens.destroy');
```

- [ ] **Step 6: Run → PASS** `ddev exec vendor/bin/pest --filter=RegistryTokenApiTest`. Pint + PHPStan grün.
- [ ] **Step 7: Commit** `feat: registry tokens rest api`.

---

### Task B6: Webhooks-API + Status

**Files:** Create `app/Http/Resources/Api/WebhookResource.php`, `app/Http/Controllers/Api/V1/{Webhook,Status}Controller.php`; Modify `routes/api.php`; Test `tests/Feature/Api/WebhookStatusApiTest.php`.

**Interfaces:**
- Consumes: `StoreWebhookRequest` (Admin), `Webhook`-Model, vorhandener Status-Aggregat-Code (aus `Admin\StatusController` bzw. Dashboard-Stats).
- Produces: `GET/POST /api/v1/webhooks`, `DELETE …/{webhook}`; `GET /api/v1/status`; `WebhookResource`.

- [ ] **Step 1: Failing test** `tests/Feature/Api/WebhookStatusApiTest.php`
```php
<?php

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin']);
    [, $this->plain] = ApiKey::issue($this->admin, 'w', \App\Enums\ApiKeyPermission::Write);
});

it('creates, lists and deletes webhooks without leaking the secret', function () {
    $res = $this->withToken($this->plain)->postJson('/api/v1/webhooks', [
        'url' => 'https://hooks.acme.test/x',
        'secret' => 'supersecret',
        'events' => ['package.synced'],
    ])->assertCreated();

    expect($res->json('data'))->not->toHaveKey('secret');
    $res->assertJsonPath('data.has_secret', true);

    $id = $res->json('data.id');
    $this->withToken($this->plain)->getJson('/api/v1/webhooks')->assertOk();
    $this->withToken($this->plain)->deleteJson("/api/v1/webhooks/{$id}")->assertNoContent();
    expect(Webhook::find($id))->toBeNull();
});

it('returns status counters', function () {
    $this->withToken($this->plain)->getJson('/api/v1/status')
        ->assertOk()->assertJsonStructure(['data' => ['packages', 'sync']]);
});
```
Passe die `events`-Werte an die tatsächlich erlaubten Event-Namen aus `StoreWebhookRequest` an (dort nachsehen).

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=WebhookStatusApiTest`.

- [ ] **Step 3: Resource** `app/Http/Resources/Api/WebhookResource.php`
```php
<?php

namespace App\Http\Resources\Api;

use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Webhook */
class WebhookResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'events' => $this->events,
            'enabled' => $this->enabled,
            'has_secret' => (bool) $this->secret,
        ];
    }
}
```

- [ ] **Step 4: Controller** `app/Http/Controllers/Api/V1/WebhookController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWebhookRequest;
use App\Http\Resources\Api\WebhookResource;
use App\Models\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WebhookController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WebhookResource::collection(Webhook::latest()->get());
    }

    public function store(StoreWebhookRequest $request): JsonResponse
    {
        $data = $request->validated();

        $webhook = Webhook::create([
            'organization_id' => $request->user()->organization_id,
            'url' => $data['url'],
            'secret' => ($data['secret'] ?? null) ?: null,
            'events' => $data['events'],
        ]);

        return (new WebhookResource($webhook))->response()->setStatusCode(201);
    }

    public function destroy(Webhook $webhook): JsonResponse
    {
        $webhook->delete();

        return response()->json(status: 204);
    }
}
```

- [ ] **Step 5: Controller** `app/Http/Controllers/Api/V1/StatusController.php` — die vorhandene Aggregat-Logik nutzen. Öffne `app/Http/Controllers/Admin/StatusController.php` (oder `DashboardController`) und übernimm die dort berechneten Sync-/Paket-Kennzahlen 1:1 als JSON:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\JsonResponse;

class StatusController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'packages' => Package::count(),
            'sync' => [
                'synced' => Package::where('sync_status', 'synced')->count(),
                'syncing' => Package::where('sync_status', 'syncing')->count(),
                'pending' => Package::where('sync_status', 'pending')->count(),
                'failed' => Package::where('sync_status', 'failed')->count(),
            ],
        ]]);
    }
}
```
Falls im Admin-`StatusController` reichhaltigere Kennzahlen existieren (Queue/failed_jobs), übernimm sie hier zusätzlich in `data`.

- [ ] **Step 6: Routen** in der Operator-Gruppe (Imports `WebhookController`, `StatusController`):
```php
Route::get('webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
Route::post('webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
Route::get('status', [StatusController::class, 'show'])->name('status');
```

- [ ] **Step 7: Run → PASS** `ddev exec vendor/bin/pest --filter=WebhookStatusApiTest`. Pint + PHPStan grün.
- [ ] **Step 8: Commit** `feat: webhooks and status rest api`.

---

### Task B7: Organizations-API (Operator-Admin)

**Files:** Create `app/Http/Resources/Api/OrganizationResource.php`, `app/Http/Controllers/Api/V1/OrganizationController.php`; Modify `routes/api.php`; Test `tests/Feature/Api/OrganizationApiTest.php`.

**Interfaces:**
- Consumes: `StoreOrganizationRequest` (Admin), `Organization`-Model.
- Produces: `GET/POST /api/v1/organizations`, `GET/DELETE …/{organization}`; **nur** `role:admin`.

- [ ] **Step 1: Failing test** `tests/Feature/Api/OrganizationApiTest.php`
```php
<?php

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets an operator admin create and delete customer orgs', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $op->id, 'role' => 'admin']);
    [, $plain] = ApiKey::issue($admin, 'w', \App\Enums\ApiKeyPermission::Write);

    $res = $this->withToken($plain)->postJson('/api/v1/organizations', ['name' => 'Kunde X', 'slug' => 'kunde-x'])
        ->assertCreated()->assertJsonPath('data.is_operator', false);

    $id = $res->json('data.id');
    $this->withToken($plain)->deleteJson("/api/v1/organizations/{$id}")->assertNoContent();
});

it('denies maintainers', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $maint = User::factory()->create(['organization_id' => $op->id, 'role' => 'maintainer']);
    [, $plain] = ApiKey::issue($maint, 'w', \App\Enums\ApiKeyPermission::Write);

    $this->withToken($plain)->getJson('/api/v1/organizations')->assertForbidden();
});
```

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=OrganizationApiTest`.

- [ ] **Step 3: Resource** `app/Http/Resources/Api/OrganizationResource.php`
```php
<?php

namespace App\Http\Resources\Api;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organization */
class OrganizationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_operator' => $this->is_operator,
        ];
    }
}
```

- [ ] **Step 4: Controller** `app/Http/Controllers/Api/V1/OrganizationController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganizationRequest;
use App\Http\Resources\Api\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return OrganizationResource::collection(
            Organization::orderBy('name')->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function show(Organization $organization): OrganizationResource
    {
        return new OrganizationResource($organization);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $org = Organization::create([...$request->validated(), 'is_operator' => false]);

        return (new OrganizationResource($org))->response()->setStatusCode(201);
    }

    public function destroy(Organization $organization): JsonResponse
    {
        if ($organization->is_operator || $organization->users()->exists() || $organization->groups()->exists()) {
            throw ValidationException::withMessages([
                'organization' => 'Organisation ist Betreiber oder nicht leer (erst Registries/Nutzer entfernen).',
            ]);
        }

        $organization->delete();

        return response()->json(status: 204);
    }
}
```

- [ ] **Step 5: Routen** — neue `role:admin`-Gruppe in `routes/api.php` (Import `OrganizationController`):
```php
Route::middleware(['operator', 'role:admin'])->group(function () {
    Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
    Route::delete('organizations/{organization}', [OrganizationController::class, 'destroy'])->name('organizations.destroy');
});
```

- [ ] **Step 6: Run → PASS** `ddev exec vendor/bin/pest --filter=OrganizationApiTest`. Pint + PHPStan grün.
- [ ] **Step 7: Commit** `feat: organizations rest api`.

---

### Task B8: Users- & Robots-API + Admin-Keys für beliebige Accounts

**Files:** Create `app/Http/Resources/Api/UserResource.php`, `app/Http/Controllers/Api/V1/{User,RobotApiKey}Controller.php`; Modify `routes/api.php`; Test `tests/Feature/Api/UserRobotApiTest.php`.

**Interfaces:**
- Consumes: `StoreUserRequest`, `UpdateUserRequest` (Admin, inkl. Rollen-Invariante), `AccountType`, `ApiKey::issue()`.
- Produces: `GET/POST /api/v1/users`, `PUT/DELETE …/{user}`; `POST /api/v1/users/{user}/api-keys` (Operator-Admin stellt Key für beliebigen Nutzer/Robot aus); Robots = Users mit `account_type=robot` (Filter `?account_type=robot`); **nur** `role:admin`.

- [ ] **Step 1: Failing test** `tests/Feature/Api/UserRobotApiTest.php`
```php
<?php

use App\Enums\AccountType;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->op = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create(['organization_id' => $this->op->id, 'role' => 'admin']);
    [, $this->plain] = ApiKey::issue($this->admin, 'w', \App\Enums\ApiKeyPermission::Write);
});

it('creates a robot account and issues a key for it', function () {
    $res = $this->withToken($this->plain)->postJson('/api/v1/users', [
        'name' => 'CI Bot',
        'email' => 'ci@acme.test',
        'organization_id' => $this->op->id,
        'role' => 'maintainer',
        'account_type' => 'robot',
    ])->assertCreated()->assertJsonPath('data.account_type', 'robot');

    $robotId = $res->json('data.id');
    expect(User::find($robotId)->account_type)->toBe(AccountType::Robot);

    $this->withToken($this->plain)->postJson("/api/v1/users/{$robotId}/api-keys", [
        'name' => 'bot-key', 'permission' => 'write',
    ])->assertCreated()->assertJsonPath('data.plain_text', fn ($v) => str_starts_with($v, 'kfxapi_'));
});

it('filters robots and enforces the operator role invariant', function () {
    $this->withToken($this->plain)->getJson('/api/v1/users?account_type=robot')->assertOk();

    // maintainer in einer Nicht-Operator-Org ist unzulässig (Invariante).
    $customer = Organization::factory()->create(['is_operator' => false]);
    $this->withToken($this->plain)->postJson('/api/v1/users', [
        'name' => 'X', 'email' => 'x@acme.test', 'organization_id' => $customer->id, 'role' => 'maintainer',
    ])->assertStatus(422);
});
```

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=UserRobotApiTest`.

- [ ] **Step 3: `account_type` in `StoreUserRequest` zulassen.** In `app/Http/Requests/Admin/StoreUserRequest.php` `rules()` ergänzen:
```php
'account_type' => ['nullable', \Illuminate\Validation\Rule::enum(\App\Enums\AccountType::class)],
```
  Das lässt die GUI unverändert (Feld optional, Default `human` greift über den DB-Default).

- [ ] **Step 4: Resource** `app/Http/Resources/Api/UserResource.php`
```php
<?php

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'account_type' => $this->account_type->value,
            'organization_id' => $this->organization_id,
        ];
    }
}
```

- [ ] **Step 5: Controller** `app/Http/Controllers/Api/V1/UserController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $type = $request->query('account_type');

        return UserResource::collection(
            User::query()
                ->when(in_array($type, ['human', 'robot'], true), fn ($q) => $q->where('account_type', $type))
                ->orderBy('name')
                ->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $isRobot = ($validated['account_type'] ?? 'human') === AccountType::Robot->value;

        // Robots haben kein Passwort; Menschen ohne Passwort erhalten ein Zufalls-Passwort.
        if ($isRobot) {
            $validated['password'] = null;
        } elseif (empty($validated['password'])) {
            $validated['password'] = Str::random(40);
        }

        $user = User::create($validated);
        $user->forceFill(['email_verified_at' => now()])->save();

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $validated = $request->validated();

        if ($user->role === UserRole::Admin
            && $user->organization->is_operator
            && ($validated['role'] ?? null) !== UserRole::Admin->value
            && $user->organization->users()->where('role', UserRole::Admin->value)->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'Der letzte Betreiber-Admin kann nicht herabgestuft werden.']);
        }

        $user->update($validated);

        return new UserResource($user);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json(status: 204);
    }
}
```
Prüfe `UpdateUserRequest` auf ein evtl. Pflichtfeld `account_type`; falls es dort nicht vorkommt, unverändert lassen.

- [ ] **Step 6: Controller** `app/Http/Controllers/Api/V1/RobotApiKeyController.php` (Operator-Admin stellt Key für beliebigen User aus)
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiKeyPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreApiKeyRequest;
use App\Http\Resources\Api\ApiKeyResource;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class RobotApiKeyController extends Controller
{
    public function store(StoreApiKeyRequest $request, User $user): JsonResponse
    {
        [$key, $plain] = ApiKey::issue(
            $user,
            $request->validated('name'),
            $request->enum('permission', ApiKeyPermission::class),
            $request->date('expires_at'),
        );

        $key->plain_text = $plain;

        return (new ApiKeyResource($key))->response()->setStatusCode(201);
    }
}
```

- [ ] **Step 7: Routen** in der `role:admin`-Gruppe (Imports `UserController`, `RobotApiKeyController`):
```php
Route::get('users', [UserController::class, 'index'])->name('users.index');
Route::post('users', [UserController::class, 'store'])->name('users.store');
Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
Route::post('users/{user}/api-keys', [RobotApiKeyController::class, 'store'])->name('users.api-keys.store');
```

- [ ] **Step 8: Run → PASS** `ddev exec vendor/bin/pest --filter=UserRobotApiTest`. Pint + PHPStan grün.
- [ ] **Step 9: Commit** `feat: users and robot accounts rest api`.

---

## PHASE C — API-Browser (Scramble)

### Task C1: Scramble-Doku unter `/docs/api` (Operator-gated)

**Files:** `composer.json` (Dependency), `config/scramble.php` (publish), `app/Providers/AppServiceProvider.php` (Gate); Test `tests/Feature/Api/DocsSpecTest.php`.

**Interfaces:**
- Consumes: alle `/api/v1`-Routen.
- Produces: `/docs/api` (UI), `/docs/api.json` (OpenAPI), hinter Operator-Gate.

- [ ] **Step 1: Dependency** `ddev composer require dedoc/scramble`. Prüfe, dass die Installation grün durchläuft und `config/scramble.php` publizierbar ist: `ddev exec php artisan vendor:publish --provider="Dedoc\Scramble\ScrambleServiceProvider" --tag=scramble-config`.

- [ ] **Step 2: Failing test** `tests/Feature/Api/DocsSpecTest.php`
```php
<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves the openapi document to an operator admin and lists api paths', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $op->id, 'role' => 'admin']);

    $res = $this->actingAs($admin)->get('/docs/api.json')->assertOk();

    expect($res->json('paths'))->toHaveKey('/api/v1/me');
});

it('denies non-operators access to the api docs', function () {
    $customer = Organization::factory()->create(['is_operator' => false]);
    $member = User::factory()->create(['organization_id' => $customer->id, 'role' => 'member']);

    $this->actingAs($member)->get('/docs/api.json')->assertForbidden();
});
```
Prüfe die exakte JSON-Route (Scramble nutzt standardmäßig `/docs/api.json`); passe den Pfad an, falls die installierte Version abweicht.

- [ ] **Step 3: Run → FAIL** `ddev exec vendor/bin/pest --filter=DocsSpecTest`.

- [ ] **Step 4: Gate + API-Pfad-Registrierung** in `app/Http/Providers` bzw. `app/Providers/AppServiceProvider.php`, `boot()`:
```php
\Dedoc\Scramble\Scramble::configure()
    ->routes(fn (\Illuminate\Routing\Route $route) => str_starts_with($route->uri, 'api/v1'));

\Illuminate\Support\Facades\Gate::define('viewApiDocs', function (\App\Models\User $user) {
    return $user->role === \App\Enums\UserRole::Admin && (bool) $user->organization?->is_operator;
});
```
  In `config/scramble.php` das `middleware` so setzen, dass die Doku-Routen `['web', 'auth']` durchlaufen, und den `Gate`-Namen `viewApiDocs` als Zugriffsschutz hinterlegen (Scramble liest `Scramble::configure()->withDocumentTransformers(...)` bzw. bietet eine `gate`-Option — nutze die in der installierten Version vorgesehene Mechanik; Ziel: nur `viewApiDocs`-berechtigte Nutzer sehen `/docs/api` und `/docs/api.json`). Verifiziere über den Test.

- [ ] **Step 5:** Falls Scramble die Gate-Integration nur über die publizierte `config/scramble.php` (`'middleware' => ['web', RestrictedDocsAccess::class]`) anbietet, belasse den Default-`RestrictedDocsAccess` und definiere den `viewApiDocs`-Gate wie oben — der Default-Guard von Scramble nutzt genau dieses Gate in Nicht-`local`-Umgebungen. Stelle im Test sicher, dass `APP_ENV=testing` als „nicht local" gilt (Gate greift).

- [ ] **Step 6: Run → PASS** `ddev exec vendor/bin/pest --filter=DocsSpecTest`. Pint + PHPStan grün. `ddev exec npm run build` (unverändert, aber prüfen).
- [ ] **Step 7: Commit** `feat: auto-generated openapi docs at /docs/api behind operator gate`.

---

## PHASE D — GUI-Verwaltung

### Task D1: Settings → API-Keys (persönlich)

**Files:** Create `app/Http/Controllers/Settings/ApiKeyController.php`, `resources/js/pages/settings/ApiKeys.vue`; Modify `routes/settings.php`, `resources/js/layouts/settings/Layout.vue`; Test `tests/Feature/Settings/ApiKeyPageTest.php`.

**Interfaces:**
- Consumes: `ApiKey::issue()`, `ApiKeyPermission`, `StoreApiKeyRequest` (Api).
- Produces: `GET/POST /settings/api-keys`, `DELETE /settings/api-keys/{apiKey}`; Inertia-Seite `settings/ApiKeys`.

- [ ] **Step 1: Failing test** `tests/Feature/Settings/ApiKeyPageTest.php`
```php
<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists only own keys and creates one with a flashed plaintext', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    ApiKey::issue($me, 'mine', ApiKeyPermission::Read);
    ApiKey::issue($other, 'theirs', ApiKeyPermission::Read);

    $this->actingAs($me)->get('/settings/api-keys')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('settings/ApiKeys')->has('apiKeys', 1));

    $this->actingAs($me)->post('/settings/api-keys', ['name' => 'deploy', 'permission' => 'write'])
        ->assertRedirect()->assertSessionHas('plainApiKey');
});

it('forbids deleting a foreign key', function () {
    $me = User::factory()->create();
    [$foreign] = ApiKey::issue(User::factory()->create(), 'x', ApiKeyPermission::Read);
    $this->actingAs($me)->delete("/settings/api-keys/{$foreign->id}")->assertForbidden();
});
```

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=ApiKeyPageTest`.

- [ ] **Step 3: Flash-Sharing** in `app/Http/Middleware/HandleInertiaRequests.php` — im `flash`-Array `'plainApiKey' => fn () => $request->session()->get('plainApiKey'),` ergänzen (neben `plainTextToken`). Ergänze in `resources/js/types/index.ts` das `flash`-Objekt um `plainApiKey?: string | null;`.

- [ ] **Step 4: Controller** `app/Http/Controllers/Settings/ApiKeyController.php`
```php
<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ApiKeyPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreApiKeyRequest;
use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/ApiKeys', [
            'apiKeys' => $request->user()->apiKeys()->latest()->get()
                ->map(fn (ApiKey $k) => [
                    'id' => $k->id,
                    'name' => $k->name,
                    'permission' => $k->permission->value,
                    'last_used_at' => $k->last_used_at?->diffForHumans(),
                    'expires_at' => $k->expires_at?->toDateString(),
                ]),
        ]);
    }

    public function store(StoreApiKeyRequest $request): RedirectResponse
    {
        [$key, $plain] = ApiKey::issue(
            $request->user(),
            $request->validated('name'),
            $request->enum('permission', ApiKeyPermission::class),
            $request->date('expires_at'),
        );

        return back()->with('plainApiKey', $plain)->with('success', "API-Key {$key->name} erstellt.");
    }

    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        abort_unless($apiKey->user_id === $request->user()->id, 403);
        $apiKey->delete();

        return back()->with('success', 'API-Key widerrufen.');
    }
}
```

- [ ] **Step 5: Routen** in `routes/settings.php` (Import `use App\Http\Controllers\Settings\ApiKeyController;`) in der `auth`-Gruppe:
```php
Route::get('settings/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
Route::post('settings/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
Route::delete('settings/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
```

- [ ] **Step 6: Seite** `resources/js/pages/settings/ApiKeys.vue` — Gerüst und Muster **exakt wie `resources/js/pages/settings/AccessTokens.vue`** (AppLayout + SettingsLayout + HeadingSmall + Klartext-Callout via `flash.plainApiKey` + Erstell-Form + Tabelle + Löschen). Unterschiede:
  - Titel „API-Keys", Beschreibung „Persönliche API-Keys für die REST-API (read/write)."
  - Props: `apiKeys: { id: string; name: string; permission: 'read'|'write'; last_used_at: string|null; expires_at: string|null }[]`.
  - Formular: Name + `permission`-Select (read/write) + optional `expires_at` (date). `useForm({ name: '', permission: 'read', expires_at: '' })`, beim Submit leere `expires_at` zu `null` transformieren, `post(route('api-keys.store'))`.
  - Tabelle: Name, Berechtigung (read/write), Zuletzt genutzt, Ablauf, Widerrufen (`route('api-keys.destroy', id)`).
  - Callout nutzt `flash.plainApiKey`.
  Öffne `AccessTokens.vue` als Vorlage und übernimm Struktur/Styling.

- [ ] **Step 7: Nav** in `resources/js/layouts/settings/Layout.vue` nach „Zugriffstokens" ergänzen:
```ts
    {
        title: 'API-Keys',
        href: '/settings/api-keys',
    },
```

- [ ] **Step 8: Run → PASS** `ddev exec vendor/bin/pest --filter=ApiKeyPageTest`. `ddev exec npm run build` + `ddev exec npm run lint` grün. Pint + PHPStan grün.
- [ ] **Step 9: Commit** `feat: personal api keys settings page`.

---

### Task D2: Admin → Robots

**Files:** Create `app/Http/Controllers/Admin/RobotController.php`, `resources/js/pages/admin/robots/Index.vue`; Modify `routes/web.php`, `resources/js/components/AppSidebar.vue`; Test `tests/Feature/Admin/RobotManagementTest.php`.

**Interfaces:**
- Consumes: `AccountType`, `ApiKey::issue()`, `User`, `Organization`.
- Produces: `GET /admin/robots`, `POST /admin/robots` (Robot anlegen), `POST /admin/robots/{user}/keys` (Key ausstellen), `DELETE /admin/robots/{user}` — Operator-Admin-gated.

- [ ] **Step 1: Failing test** `tests/Feature/Admin/RobotManagementTest.php`
```php
<?php

use App\Enums\AccountType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets an operator admin create a robot and issue a key', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $op->id, 'role' => 'admin']);

    $this->actingAs($admin)->post('/admin/robots', [
        'name' => 'CI', 'email' => 'ci@acme.test', 'organization_id' => $op->id, 'role' => 'maintainer',
    ])->assertRedirect();

    $robot = User::firstWhere('email', 'ci@acme.test');
    expect($robot->account_type)->toBe(AccountType::Robot);

    $this->actingAs($admin)->post("/admin/robots/{$robot->id}/keys", ['name' => 'k', 'permission' => 'write'])
        ->assertRedirect()->assertSessionHas('plainApiKey');
});

it('denies non-operator members', function () {
    $customer = Organization::factory()->create(['is_operator' => false]);
    $member = User::factory()->create(['organization_id' => $customer->id, 'role' => 'member']);
    $this->actingAs($member)->get('/admin/robots')->assertForbidden();
});
```

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=RobotManagementTest`.

- [ ] **Step 3: Controller** `app/Http/Controllers/Admin/RobotController.php`
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RobotController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/robots/Index', [
            'robots' => User::where('account_type', AccountType::Robot)->with('organization:id,name')->orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role->value,
                    'organization' => $u->organization?->name,
                    'keys_count' => $u->apiKeys()->count(),
                ]),
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);

        // Operator-Invariante: privilegierte Rollen nur in der Betreiber-Org.
        $org = Organization::findOrFail($validated['organization_id']);
        if (in_array($validated['role'], [UserRole::Admin->value, UserRole::Maintainer->value], true) && ! $org->is_operator) {
            return back()->withErrors(['role' => 'Admin/Maintainer sind nur in der Betreiber-Organisation erlaubt.']);
        }

        $robot = User::create([...$validated, 'account_type' => AccountType::Robot, 'password' => null]);
        $robot->forceFill(['email_verified_at' => now()])->save();

        return back()->with('success', "Robot {$robot->name} angelegt.");
    }

    public function issueKey(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->account_type === AccountType::Robot, 404);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'permission' => ['required', Rule::enum(ApiKeyPermission::class)],
        ]);

        [, $plain] = ApiKey::issue($user, $validated['name'], ApiKeyPermission::from($validated['permission']));

        return back()->with('plainApiKey', $plain)->with('success', 'API-Key erstellt.');
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_unless($user->account_type === AccountType::Robot, 404);
        $user->delete();

        return back()->with('success', 'Robot gelöscht.');
    }
}
```

- [ ] **Step 4: Routen** in `routes/web.php` in der `role:admin`-Operator-Gruppe (Import `Admin\RobotController` bzw. FQCN) ergänzen:
```php
Route::get('robots', [Admin\RobotController::class, 'index'])->name('robots.index');
Route::post('robots', [Admin\RobotController::class, 'store'])->name('robots.store');
Route::post('robots/{user}/keys', [Admin\RobotController::class, 'issueKey'])->name('robots.keys.store');
Route::delete('robots/{user}', [Admin\RobotController::class, 'destroy'])->name('robots.destroy');
```

- [ ] **Step 5: Seite** `resources/js/pages/admin/robots/Index.vue` — AppLayout-Seite im Stil von `resources/js/pages/admin/users/Index.vue` (als Vorlage öffnen): Tabelle der Robots (Name, E-Mail, Rolle, Org, #Keys, Aktionen: Key ausstellen / löschen), Erstell-Form (Name, E-Mail, Org-Select, Rollen-Select), Klartext-Callout via `flash.plainApiKey` (Muster wie AccessTokens.vue), „Key ausstellen"-Dialog/-Inline-Form je Robot (`route('admin.robots.keys.store', robot.id)`).

- [ ] **Step 6: Sidebar** in `resources/js/components/AppSidebar.vue` — im `isAdmin`-Block der Sektion „Verwaltung" einen Eintrag ergänzen: `{ title: 'Robots', href: '/admin/robots', icon: Bot }` (Icon `Bot` aus `lucide-vue-next` importieren).

- [ ] **Step 7: Run → PASS** `ddev exec vendor/bin/pest --filter=RobotManagementTest`. `ddev exec npm run build` + `ddev exec npm run lint` grün. Pint + PHPStan grün.
- [ ] **Step 8: Commit** `feat: admin robot account management`.

---

## PHASE E — Verifikation

### Task E1: Volle Verifikation + Security-Review-Übergabe

- [ ] **Step 1: Autorisierungs-Matrix-Ergänzung** `tests/Feature/Api/AuthorizationMatrixTest.php` — Querschnittsfälle, die einzelne Ressourcen-Tests nicht abdecken:
```php
<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('read key is blocked on every mutating verb', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $op->id, 'role' => 'admin']);
    [, $read] = ApiKey::issue($admin, 'r', ApiKeyPermission::Read);

    $this->withToken($read)->postJson('/api/v1/groups', [])->assertForbidden();
    $this->withToken($read)->putJson('/api/v1/groups/x', [])->assertForbidden();
    $this->withToken($read)->deleteJson('/api/v1/groups/x')->assertForbidden();
});

it('expired key is unauthorized', function () {
    $u = User::factory()->create();
    [, $plain] = ApiKey::issue($u, 'old', ApiKeyPermission::Read, now()->subMinute());
    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
});

it('rate limits after the configured threshold', function () {
    $u = User::factory()->create();
    [, $plain] = ApiKey::issue($u, 'rl', ApiKeyPermission::Read);

    foreach (range(1, 120) as $_) {
        $this->withToken($plain)->getJson('/api/v1/me');
    }
    $this->withToken($plain)->getJson('/api/v1/me')->assertStatus(429);
});
```

- [ ] **Step 2: Run** `ddev exec vendor/bin/pest --filter=AuthorizationMatrixTest` → grün. Falls der Rate-Limit-Test flakig ist (Limiter-Cache), im Test `RateLimiter::clear('api:'.…)` in `beforeEach` bzw. `Cache::flush()` ergänzen.

- [ ] **Step 3: Volle Suite** `ddev exec vendor/bin/pest` — **Gesamtzahl melden** (Ausgangsbasis 397 + neue Tests). Alle grün.
- [ ] **Step 4:** `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run lint`, `ddev exec npm run build` — alles grün.
- [ ] **Step 5: Commit** `test: api authorization matrix and full verification`.
- [ ] **Step 6:** Übergabe an den Haupt-Agenten für das **adversariale Opus-Security-Review** (Fokus: Key-Isolation über `user_id`, Umgehung des read/write-Gates, Rolle-∩-Perm-Lücken, Secret-Leak in Resources/`plain_text`, Robot-Login-Bypass über OIDC/Passkey/2FA, Doku-Gating in Nicht-local-Umgebung, Cross-Org-Zugriff) **vor dem Push**.

---

## Self-Review (Plan ↔ Spec)

- **Spec-Abdeckung:** ① Zugriffskanal/Format → A3 (Routing, Throttle, Resources). ② Auth/Perm-Modell → A2 (Modell), A3 (Middleware, Rolle∩Perm, read/write-Gate). ③ Robot-Accounts → A1 (Typ), A4 (Login-Sperre), B8/D2 (Verwaltung). ④ Ressourcen → B2–B8. ⑤ API-Browser → C1. ⑥ GUI-Verwaltung → D1 (Settings-Keys), D2 (Robots). ⑦ Tests/Sicherheit → jede Task + E1.
- **Platzhalter:** keine offenen TODO/TBD; jeder Code-Schritt zeigt vollständigen Code oder verweist präzise auf eine zu öffnende Vorlagedatei (AccessTokens.vue, users/Index.vue) mit exakter Diff-Beschreibung.
- **Typkonsistenz:** `ApiKey::issue()`, `ApiKey::findByPlainText()`, `ApiKeyPermission`, `AccountType`, `isRobot()`, `apiKeys()`, Resource-Feld `plain_text`, Flash `plainApiKey` sind über alle Tasks konsistent benannt.
- **Risiken/Annahmen (im Bericht zu verifizieren):** Scramble-Gate-Mechanik variiert je Version (C1 Step 4/5 gibt zwei Wege vor); `Group::domains()/upstreams()->create()`-Fillables (B4 Step 5 Hinweis); `UpdateUserRequest`-Feldsatz (B8 Step 5). Diese sind mit expliziten Prüf-Hinweisen versehen.
- **Verschoben (Runde 2 – Public-Readiness):** öffentliche Freigabe von `/docs/api`, öffentliche Read-Endpunkte, Security-Audit, README/Lizenz, Repo public.

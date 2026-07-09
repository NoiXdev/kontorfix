# Kontorfix v0.9 – OIDC/SSO Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Betreiber können per GUI generische OIDC-Provider (Authentik, Keycloak, GitHub-über-OIDC, …) anlegen; Nutzer melden sich per „Mit {Provider} anmelden" an. Authorization-Code-Flow mit PKCE, state und nonce; id_token-Signatur wird gegen die JWKS des Providers verifiziert.

**Architecture:** DB-gespeicherte, verschlüsselte Provider-Konfiguration (`oidc_providers`), Endpunkte per OIDC-Discovery befüllbar. Eine föderierte Identität (`sub`-Claim) wird in `oidc_identities` an einen lokalen User gebunden. Login-Regel: bestehende Identität → deren User; sonst Match über **verifizierte** E-Mail an einen bestehenden User (+ Verknüpfung); sonst Auto-Provisioning nur wenn der Provider es erlaubt (in konfigurierte Default-Org/Rolle); sonst Ablehnung. Föderierter Login schließt direkt ab (der IdP verantwortet MFA) — konsistent mit der Passkey-Entscheidung (v0.8), kein zusätzlicher TOTP-Schritt. SSRF-Absicherung aller ausgehenden Abrufe (Discovery, Token, JWKS) über das vorhandene `App\Services\Upstream\UrlSafety`.

**Tech Stack:** Laravel 12, Inertia v2 + Vue 3, `firebase/php-jwt` (JWKS/RS256), Laravel HTTP-Client (`Http::`, in Tests via `Http::fake`), Pest, Pint, Larastan L6.

---

## File Structure

- Create migrations: `oidc_providers`, `oidc_identities`.
- Create `app/Models/OidcProvider.php`, `app/Models/OidcIdentity.php`; modify `app/Models/User.php` (Relation `oidcIdentities`).
- Create `app/Services/Auth/Oidc/OidcDiscovery.php` — `.well-known`-Discovery (SSRF-guarded).
- Create `app/Services/Auth/Oidc/OidcService.php` — Redirect-URL (PKCE/state/nonce), Code-Tausch, id_token-Verifikation (JWKS/RS256 + Claims).
- Create `app/Services/Auth/Oidc/OidcUserResolver.php` — Identität/E-Mail-Match + Provisioning.
- Create `app/Http/Controllers/Auth/OidcController.php` — redirect/callback.
- Create `app/Http/Controllers/Admin/OidcProviderController.php` + `app/Http/Requests/Admin/StoreOidcProviderRequest.php`.
- Modify `routes/auth.php` (oidc redirect/callback in `guest`-Gruppe) und `routes/web.php` (admin oidc-Ressource, `role:admin`).
- Modify `app/Http/Controllers/Auth/AuthenticatedSessionController.php@create` — aktivierte Provider an die Login-Seite geben.
- Create `resources/js/pages/admin/oidc/Index.vue`; modify `resources/js/pages/auth/Login.vue` (SSO-Buttons).
- Create `docs/oidc-setup.md` (Betreiber-Doku inkl. redirect_uri, Sicherheitsmodell).
- Tests unter `tests/Unit/Oidc/` und `tests/Feature/Auth/`, `tests/Feature/Admin/`.

---

### Task O0: Paket installieren

- [ ] **Step 1:** `ddev composer require firebase/php-jwt`
- [ ] **Step 2:** Verifizieren: `ddev exec php -r "require 'vendor/autoload.php'; echo class_exists(\Firebase\JWT\JWT::class) ? 'OK' : 'MISSING';"` → `OK`
- [ ] **Step 3:** Commit `build: add firebase/php-jwt for oidc id_token verification`

---

### Task O1: Schema + Modelle

**Files:** zwei Migrationen, `app/Models/OidcProvider.php`, `app/Models/OidcIdentity.php`, `app/Models/User.php`, Factories, Test `tests/Unit/Oidc/OidcModelTest.php`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\OidcIdentity;
use App\Models\OidcProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores an oidc provider with an encrypted secret and links identities', function () {
    $provider = OidcProvider::factory()->create([
        'slug' => 'authentik',
        'client_secret' => 'supersecret',
        'scopes' => 'openid email profile',
        'enabled' => true,
    ]);

    // Secret ist verschlüsselt at rest.
    $raw = \DB::table('oidc_providers')->where('id', $provider->id)->value('client_secret');
    expect($raw)->not->toBe('supersecret');
    expect($provider->client_secret)->toBe('supersecret');

    $user = User::factory()->create();
    $identity = OidcIdentity::create([
        'oidc_provider_id' => $provider->id,
        'user_id' => $user->id,
        'subject' => 'sub-123',
    ]);

    expect($identity->user->is($user))->toBeTrue();
    expect($user->oidcIdentities()->count())->toBe(1);
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: Migration `oidc_providers`** (`ddev exec php artisan make:migration create_oidc_providers_table`):

```php
Schema::create('oidc_providers', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('client_id');
    $table->text('client_secret');                 // encrypted cast
    $table->string('issuer')->nullable();
    $table->string('authorization_endpoint')->nullable();
    $table->string('token_endpoint')->nullable();
    $table->string('userinfo_endpoint')->nullable();
    $table->string('jwks_uri')->nullable();
    $table->string('scopes')->default('openid email profile');
    $table->boolean('enabled')->default(false);
    $table->boolean('allow_registration')->default(false);
    $table->foreignUuid('default_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
    $table->string('default_role')->default('member');
    $table->timestamps();
});
```

**Migration `oidc_identities`:**

```php
Schema::create('oidc_identities', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('oidc_provider_id')->constrained('oidc_providers')->cascadeOnDelete();
    $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
    $table->string('subject');                      // der `sub`-Claim
    $table->timestamp('last_login_at')->nullable();
    $table->timestamps();
    $table->unique(['oidc_provider_id', 'subject']);
});
```

- [ ] **Step 4: Modelle**

`OidcProvider` (`HasUuids`, `HasFactory`): `$fillable` alle Konfig-Felder; casts `['client_secret' => 'encrypted', 'enabled' => 'bool', 'allow_registration' => 'bool', 'default_role' => UserRole::class]`; `$hidden = ['client_secret']`; Relation `identities(): HasMany`. Helfer `hasSecret(): bool { return filled($this->client_secret); }`. Scope oder statischer Zugriff für aktivierte Provider ist optional.

`OidcIdentity` (`HasUuids`, `HasFactory`): `$fillable = ['oidc_provider_id','user_id','subject','last_login_at']`; casts `['last_login_at' => 'datetime']`; Relationen `provider(): BelongsTo`, `user(): BelongsTo`.

`User`: Relation ergänzen:
```php
/** @return HasMany<OidcIdentity, $this> */
public function oidcIdentities(): HasMany
{
    return $this->hasMany(OidcIdentity::class);
}
```

Factories: `OidcProviderFactory` (sinnvolle Defaults inkl. gültiger https-Endpunkte, `client_secret` gesetzt) und `OidcIdentityFactory`.

- [ ] **Step 5:** `ddev exec php artisan migrate`; Test → PASS; Pint + PHPStan.
- [ ] **Step 6:** Commit `feat: oidc provider and identity schema and models`.

---

### Task O2: OidcDiscovery (SSRF-guarded)

Fetcht `{issuer}/.well-known/openid-configuration` und liefert die Endpunkte. Alle abgerufenen URLs (issuer + die vom Provider gelieferten Endpunkte) werden mit `App\Services\Upstream\UrlSafety::isSafe()` geprüft — verhindert SSRF über eine bösartige/vertippte issuer-URL. Keine Redirects folgen.

**Files:** `app/Services/Auth/Oidc/OidcDiscovery.php`, Test `tests/Unit/Oidc/OidcDiscoveryTest.php`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Services\Auth\Oidc\OidcDiscovery;
use Illuminate\Support\Facades\Http;

it('resolves endpoints from the discovery document', function () {
    Http::fake([
        'https://idp.test/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://idp.test',
            'authorization_endpoint' => 'https://idp.test/authorize',
            'token_endpoint' => 'https://idp.test/token',
            'userinfo_endpoint' => 'https://idp.test/userinfo',
            'jwks_uri' => 'https://idp.test/jwks',
        ]),
    ]);

    $config = app(OidcDiscovery::class)->discover('https://idp.test');

    expect($config['authorization_endpoint'])->toBe('https://idp.test/authorize')
        ->and($config['token_endpoint'])->toBe('https://idp.test/token')
        ->and($config['jwks_uri'])->toBe('https://idp.test/jwks');
});

it('rejects an unsafe issuer url', function () {
    app(OidcDiscovery::class)->discover('http://127.0.0.1/x');
})->throws(RuntimeException::class);

it('rejects a discovery document pointing endpoints at internal hosts', function () {
    Http::fake([
        'https://evil.test/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://evil.test',
            'authorization_endpoint' => 'http://169.254.169.254/latest',
            'token_endpoint' => 'https://evil.test/token',
            'jwks_uri' => 'https://evil.test/jwks',
        ]),
    ]);

    app(OidcDiscovery::class)->discover('https://evil.test');
})->throws(RuntimeException::class);
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Auth\Oidc;

use App\Services\Upstream\UrlSafety;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OidcDiscovery
{
    /**
     * @return array{issuer:string,authorization_endpoint:string,token_endpoint:string,userinfo_endpoint:?string,jwks_uri:string}
     */
    public function discover(string $issuer): array
    {
        if (! UrlSafety::isSafe($issuer)) {
            throw new RuntimeException('Unsichere issuer-URL.');
        }

        $url = rtrim($issuer, '/').'/.well-known/openid-configuration';

        $response = Http::timeout(10)->withoutRedirecting()->acceptJson()->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("Discovery fehlgeschlagen (HTTP {$response->status()}).");
        }

        /** @var array<string,mixed> $doc */
        $doc = $response->json();

        $endpoints = [
            'issuer' => (string) ($doc['issuer'] ?? ''),
            'authorization_endpoint' => (string) ($doc['authorization_endpoint'] ?? ''),
            'token_endpoint' => (string) ($doc['token_endpoint'] ?? ''),
            'userinfo_endpoint' => isset($doc['userinfo_endpoint']) ? (string) $doc['userinfo_endpoint'] : null,
            'jwks_uri' => (string) ($doc['jwks_uri'] ?? ''),
        ];

        // Jeden vom IdP gelieferten Endpunkt gegen SSRF prüfen.
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            if (! UrlSafety::isSafe($endpoints[$key])) {
                throw new RuntimeException("Unsicherer/fehlender Endpunkt: {$key}.");
            }
        }
        if ($endpoints['userinfo_endpoint'] !== null && ! UrlSafety::isSafe($endpoints['userinfo_endpoint'])) {
            throw new RuntimeException('Unsicherer userinfo_endpoint.');
        }

        return $endpoints;
    }
}
```

- [ ] **Step 4:** Run → PASS; Pint + PHPStan.
- [ ] **Step 5:** Commit `feat: oidc discovery with ssrf-guarded endpoint resolution`.

---

### Task O3: OidcService — Redirect-URL (PKCE/state/nonce)

Baut die Authorization-Redirect-URL. state, nonce und PKCE-code_verifier werden vom Aufrufer erzeugt und in die Session gelegt (Task O5); dieser Service ist rein (leicht testbar).

**Files:** `app/Services/Auth/Oidc/OidcService.php` (Teil 1), Test `tests/Unit/Oidc/OidcServiceRedirectTest.php`.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\OidcProvider;
use App\Services\Auth\Oidc\OidcService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds an authorization url with pkce, state and nonce', function () {
    $provider = OidcProvider::factory()->create([
        'authorization_endpoint' => 'https://idp.test/authorize',
        'client_id' => 'client-abc',
        'scopes' => 'openid email profile',
    ]);

    $url = app(OidcService::class)->authorizationUrl(
        $provider,
        redirectUri: 'https://app.test/auth/oidc/authentik/callback',
        state: 'the-state',
        nonce: 'the-nonce',
        codeChallenge: 'the-challenge',
    );

    expect($url)->toStartWith('https://idp.test/authorize?');
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    expect($q['response_type'])->toBe('code')
        ->and($q['client_id'])->toBe('client-abc')
        ->and($q['redirect_uri'])->toBe('https://app.test/auth/oidc/authentik/callback')
        ->and($q['scope'])->toBe('openid email profile')
        ->and($q['state'])->toBe('the-state')
        ->and($q['nonce'])->toBe('the-nonce')
        ->and($q['code_challenge'])->toBe('the-challenge')
        ->and($q['code_challenge_method'])->toBe('S256');
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: Implement** (Service anlegen, weitere Methoden folgen in O4):

```php
<?php

namespace App\Services\Auth\Oidc;

use App\Models\OidcProvider;

class OidcService
{
    public function authorizationUrl(OidcProvider $provider, string $redirectUri, string $state, string $nonce, string $codeChallenge): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $provider->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => $provider->scopes,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return $provider->authorization_endpoint.'?'.$query;
    }
}
```

- [ ] **Step 4:** Run → PASS; Pint + PHPStan.
- [ ] **Step 5:** Commit `feat: oidc authorization url builder with pkce`.

---

### Task O4: OidcService — Code-Tausch + id_token-Verifikation

Tauscht den Auth-Code am token_endpoint (confidential client, client_secret, PKCE-verifier) und verifiziert das id_token: Signatur gegen JWKS (RS256), sowie iss/aud/exp/nonce.

**Files:** `app/Services/Auth/Oidc/OidcService.php` (erweitern), Test `tests/Unit/Oidc/OidcServiceTokenTest.php`.

- [ ] **Step 1: Failing test** — nutzt einen echten RSA-Schlüssel, signiert ein id_token und stellt die JWKS via `Http::fake` bereit.

```php
<?php

use App\Models\OidcProvider;
use App\Services\Auth\Oidc\OidcService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** @return array{provider:OidcProvider,idToken:string,jwks:array} */
function oidcFixture(array $claimOverrides = []): array
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $privatePem);
    $details = openssl_pkey_get_details($res);

    $b64url = fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    $jwks = ['keys' => [[
        'kty' => 'RSA', 'use' => 'sig', 'kid' => 'test-key', 'alg' => 'RS256',
        'n' => $b64url($details['rsa']['n']), 'e' => $b64url($details['rsa']['e']),
    ]]];

    $provider = OidcProvider::factory()->create([
        'issuer' => 'https://idp.test', 'client_id' => 'client-abc',
        'token_endpoint' => 'https://idp.test/token', 'jwks_uri' => 'https://idp.test/jwks',
    ]);

    $claims = array_merge([
        'iss' => 'https://idp.test', 'aud' => 'client-abc',
        'sub' => 'sub-123', 'email' => 'user@idp.test', 'email_verified' => true,
        'nonce' => 'the-nonce', 'exp' => time() + 300, 'iat' => time(),
    ], $claimOverrides);

    $idToken = JWT::encode($claims, $privatePem, 'RS256', 'test-key');

    return ['provider' => $provider, 'idToken' => $idToken, 'jwks' => $jwks];
}

it('exchanges the code and returns a verified id_token payload', function () {
    ['provider' => $provider, 'idToken' => $idToken, 'jwks' => $jwks] = oidcFixture();

    Http::fake([
        'https://idp.test/token' => Http::response(['id_token' => $idToken, 'access_token' => 'at-1', 'token_type' => 'Bearer']),
        'https://idp.test/jwks' => Http::response($jwks),
    ]);

    $service = app(OidcService::class);
    $tokens = $service->exchangeCode($provider, code: 'the-code', codeVerifier: 'verifier', redirectUri: 'https://app.test/cb');
    $claims = $service->verifyIdToken($provider, $tokens['id_token'], nonce: 'the-nonce');

    expect($claims['sub'])->toBe('sub-123')
        ->and($claims['email'])->toBe('user@idp.test')
        ->and($claims['email_verified'])->toBeTrue();
});

it('rejects an id_token with the wrong nonce', function () {
    ['provider' => $provider, 'idToken' => $idToken, 'jwks' => $jwks] = oidcFixture();
    Http::fake(['https://idp.test/jwks' => Http::response($jwks)]);

    app(OidcService::class)->verifyIdToken($provider, $idToken, nonce: 'different-nonce');
})->throws(RuntimeException::class);

it('rejects an id_token with the wrong audience', function () {
    ['provider' => $provider, 'idToken' => $idToken, 'jwks' => $jwks] = oidcFixture(['aud' => 'someone-else']);
    Http::fake(['https://idp.test/jwks' => Http::response($jwks)]);

    app(OidcService::class)->verifyIdToken($provider, $idToken, nonce: 'the-nonce');
})->throws(RuntimeException::class);
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: Implement** (Methoden zu `OidcService` ergänzen):

```php
use App\Services\Upstream\UrlSafety;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * @return array{id_token:string,access_token:?string}
 */
public function exchangeCode(OidcProvider $provider, string $code, string $codeVerifier, string $redirectUri): array
{
    if (! UrlSafety::isSafe($provider->token_endpoint)) {
        throw new RuntimeException('Unsicherer token_endpoint.');
    }

    $response = Http::asForm()->timeout(10)->withoutRedirecting()->acceptJson()->post($provider->token_endpoint, [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'client_id' => $provider->client_id,
        'client_secret' => $provider->client_secret,
        'code_verifier' => $codeVerifier,
    ]);

    if (! $response->successful() || ! is_string($response->json('id_token'))) {
        throw new RuntimeException('Token-Tausch fehlgeschlagen.');
    }

    return ['id_token' => $response->json('id_token'), 'access_token' => $response->json('access_token')];
}

/**
 * Verifiziert Signatur (JWKS/RS256) und Claims (iss, aud, exp via JWT::decode; nonce).
 *
 * @return array<string,mixed>
 */
public function verifyIdToken(OidcProvider $provider, string $idToken, string $nonce): array
{
    if (! UrlSafety::isSafe($provider->jwks_uri)) {
        throw new RuntimeException('Unsichere jwks_uri.');
    }

    $jwks = Http::timeout(10)->withoutRedirecting()->acceptJson()->get($provider->jwks_uri)->json();
    if (! is_array($jwks) || ! isset($jwks['keys'])) {
        throw new RuntimeException('JWKS konnte nicht geladen werden.');
    }

    try {
        // JWT::decode prüft Signatur + exp/nbf/iat automatisch.
        $keys = JWK::parseKeySet($jwks);
        $payload = (array) JWT::decode($idToken, $keys);
    } catch (\Throwable $e) {
        throw new RuntimeException('id_token-Signatur ungültig: '.$e->getMessage());
    }

    if (($payload['iss'] ?? null) !== $provider->issuer) {
        throw new RuntimeException('iss stimmt nicht mit dem Provider überein.');
    }

    $aud = $payload['aud'] ?? null;
    $audOk = is_array($aud) ? in_array($provider->client_id, $aud, true) : $aud === $provider->client_id;
    if (! $audOk) {
        throw new RuntimeException('aud stimmt nicht mit der client_id überein.');
    }

    if (! hash_equals($nonce, (string) ($payload['nonce'] ?? ''))) {
        throw new RuntimeException('nonce stimmt nicht — möglicher Replay.');
    }

    return $payload;
}
```

Hinweis: `JWT::decode` benötigt ggf. `JWT::$leeway` für Clock-Skew — Default 0 ist für Tests ok. Falls PHPStan über `$response->json(...)`-`mixed` meckert, mit `is_string`/Casts absichern (wie oben teils gezeigt).

- [ ] **Step 4:** Run → alle PASS; Pint + PHPStan.
- [ ] **Step 5:** Commit `feat: oidc code exchange and id_token jwks verification`.

---

### Task O5: OidcUserResolver + OidcController (Login-Flow)

Bindet den verifizierten id_token an einen lokalen User und loggt ein. Regel (in dieser Reihenfolge):
1. Bestehende `OidcIdentity` (provider, sub) → deren User.
2. Sonst: User mit passender, **verifizierter** E-Mail (`email_verified === true` im Claim) → Identität anlegen, verknüpfen.
3. Sonst, wenn `provider.allow_registration`: neuen User in `default_organization_id` mit `default_role` anlegen (Fehler, wenn keine Default-Org gesetzt), Identität anlegen.
4. Sonst: Ablehnung (Redirect zur Login-Seite mit Fehlermeldung).

Föderierter Login schließt direkt ab (kein TOTP-Schritt — IdP verantwortet MFA; konsistent mit v0.8-Passkeys).

**Files:** `app/Services/Auth/Oidc/OidcUserResolver.php`, `app/Http/Controllers/Auth/OidcController.php`, `routes/auth.php`, Tests `tests/Feature/Auth/OidcLoginTest.php`.

- [ ] **Step 1: Failing test** — der Callback-Test faked die IdP-HTTP-Endpunkte (Token + JWKS) mit demselben RSA-Fixture wie O4; state/nonce/verifier werden vorab in die Session gelegt.

```php
<?php

use App\Models\OidcProvider;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

function fakeIdp(OidcProvider $provider, array $claimOverrides = []): void
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $pem);
    $d = openssl_pkey_get_details($res);
    $b = fn (string $x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $jwks = ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'kid' => 'k', 'alg' => 'RS256', 'n' => $b($d['rsa']['n']), 'e' => $b($d['rsa']['e'])]]];
    $claims = array_merge(['iss' => $provider->issuer, 'aud' => $provider->client_id, 'sub' => 'sub-1',
        'email' => 'sso@idp.test', 'email_verified' => true, 'nonce' => 'nonce-1', 'exp' => time() + 300, 'iat' => time()], $claimOverrides);
    $idToken = JWT::encode($claims, $pem, 'RS256', 'k');
    Http::fake([
        $provider->token_endpoint => Http::response(['id_token' => $idToken, 'access_token' => 'at']),
        $provider->jwks_uri => Http::response($jwks),
    ]);
}

function primeSession($test, OidcProvider $provider): void
{
    $test->withSession(['oidc' => ['state' => 'state-1', 'nonce' => 'nonce-1', 'verifier' => 'ver-1', 'provider' => $provider->slug]]);
}

beforeEach(function () {
    $this->provider = OidcProvider::factory()->create([
        'slug' => 'authentik', 'enabled' => true, 'issuer' => 'https://idp.test', 'client_id' => 'client-abc',
        'authorization_endpoint' => 'https://idp.test/authorize', 'token_endpoint' => 'https://idp.test/token', 'jwks_uri' => 'https://idp.test/jwks',
    ]);
});

it('redirects to the identity provider with a stored state', function () {
    $res = $this->get('/auth/oidc/authentik/redirect');
    $res->assertRedirect();
    expect($res->headers->get('Location'))->toStartWith('https://idp.test/authorize?');
    expect(session('oidc.provider'))->toBe('authentik');
});

it('logs in an existing user matched by verified email and links the identity', function () {
    $user = User::factory()->create(['email' => 'sso@idp.test']);
    fakeIdp($this->provider);
    primeSession($this, $this->provider);

    $this->get('/auth/oidc/authentik/callback?code=the-code&state=state-1')
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($this->provider->identities()->where('subject', 'sub-1')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('logs in a returning user via the stored identity', function () {
    $user = User::factory()->create(['email' => 'changed@elsewhere.test']);
    $this->provider->identities()->create(['user_id' => $user->id, 'subject' => 'sub-1']);
    fakeIdp($this->provider); // email im Claim weicht ab — Identität zählt
    primeSession($this, $this->provider);

    $this->get('/auth/oidc/authentik/callback?code=c&state=state-1')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

it('rejects an unknown user when registration is disabled', function () {
    fakeIdp($this->provider); // kein lokaler User mit sso@idp.test
    primeSession($this, $this->provider);

    $this->get('/auth/oidc/authentik/callback?code=c&state=state-1')
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

it('provisions a new user when the provider allows registration', function () {
    $org = \App\Models\Organization::factory()->create();
    $this->provider->update(['allow_registration' => true, 'default_organization_id' => $org->id, 'default_role' => 'member']);
    fakeIdp($this->provider);
    primeSession($this, $this->provider);

    $this->get('/auth/oidc/authentik/callback?code=c&state=state-1')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();
    expect(User::where('email', 'sso@idp.test')->exists())->toBeTrue();
});

it('rejects a callback whose state does not match the session', function () {
    fakeIdp($this->provider);
    primeSession($this, $this->provider);

    $this->get('/auth/oidc/authentik/callback?code=c&state=WRONG')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('does not require the totp step even if the user has 2fa (federated mfa)', function () {
    $user = User::factory()->create(['email' => 'sso@idp.test']);
    $user->forceFill(['two_factor_secret' => app(\App\Services\Auth\TwoFactorAuthenticator::class)->generateSecret(), 'two_factor_confirmed_at' => now()])->save();
    fakeIdp($this->provider);
    primeSession($this, $this->provider);

    $this->get('/auth/oidc/authentik/callback?code=c&state=state-1')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: OidcUserResolver**

```php
<?php

namespace App\Services\Auth\Oidc;

use App\Enums\UserRole;
use App\Models\OidcProvider;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class OidcUserResolver
{
    /** @param array<string,mixed> $claims */
    public function resolve(OidcProvider $provider, array $claims): User
    {
        $subject = (string) ($claims['sub'] ?? '');
        if ($subject === '') {
            throw new RuntimeException('id_token ohne sub.');
        }

        // 1) Bestehende Identität.
        $identity = $provider->identities()->where('subject', $subject)->first();
        if ($identity !== null) {
            $identity->update(['last_login_at' => now()]);

            return $identity->user;
        }

        $email = (string) ($claims['email'] ?? '');
        $emailVerified = ($claims['email_verified'] ?? false) === true;

        // 2) Match über verifizierte E-Mail an bestehenden User → verknüpfen.
        if ($email !== '' && $emailVerified) {
            $user = User::where('email', $email)->first();
            if ($user !== null) {
                $this->link($provider, $user, $subject);

                return $user;
            }
        }

        // 3) Auto-Provisioning nur wenn erlaubt.
        if ($provider->allow_registration) {
            if ($provider->default_organization_id === null) {
                throw new RuntimeException('Provider erlaubt Registrierung, hat aber keine Default-Organisation.');
            }
            if ($email === '' || ! $emailVerified) {
                throw new RuntimeException('Registrierung erfordert eine verifizierte E-Mail.');
            }

            $user = User::create([
                'name' => (string) ($claims['name'] ?? $email),
                'email' => $email,
                'password' => bcrypt(Str::random(40)),
                'organization_id' => $provider->default_organization_id,
                'role' => $provider->default_role ?? UserRole::Member,
                'email_verified_at' => now(),
            ]);
            $this->link($provider, $user, $subject);

            return $user;
        }

        // 4) Ablehnung.
        throw new RuntimeException('Kein Konto für diese Identität — Registrierung ist deaktiviert.');
    }

    private function link(OidcProvider $provider, User $user, string $subject): void
    {
        $provider->identities()->create(['user_id' => $user->id, 'subject' => $subject, 'last_login_at' => now()]);
    }
}
```

- [ ] **Step 4: OidcController**

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OidcProvider;
use App\Services\Auth\Oidc\OidcService;
use App\Services\Auth\Oidc\OidcUserResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

class OidcController extends Controller
{
    public function __construct(private OidcService $service, private OidcUserResolver $resolver) {}

    public function redirect(Request $request, string $slug): RedirectResponse
    {
        $provider = OidcProvider::where('slug', $slug)->where('enabled', true)->firstOrFail();

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request->session()->put('oidc', ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'provider' => $slug]);

        return redirect()->away($this->service->authorizationUrl($provider, $this->callbackUri($slug), $state, $nonce, $challenge));
    }

    public function callback(Request $request, string $slug): RedirectResponse
    {
        $session = $request->session()->pull('oidc', []);

        // state + Provider-Konsistenz (CSRF-Schutz des OAuth-Flows).
        if (($session['provider'] ?? null) !== $slug
            || ! is_string($request->query('state'))
            || ! hash_equals((string) ($session['state'] ?? ''), (string) $request->query('state'))
            || ! is_string($request->query('code'))) {
            return redirect()->route('login')->withErrors(['email' => __('Anmeldung über den Identity-Provider fehlgeschlagen.')]);
        }

        $provider = OidcProvider::where('slug', $slug)->where('enabled', true)->firstOrFail();

        try {
            $tokens = $this->service->exchangeCode($provider, (string) $request->query('code'), (string) $session['verifier'], $this->callbackUri($slug));
            $claims = $this->service->verifyIdToken($provider, $tokens['id_token'], (string) $session['nonce']);
            $user = $this->resolver->resolve($provider, $claims);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->withErrors(['email' => __('Anmeldung über den Identity-Provider fehlgeschlagen.')]);
        }

        // Föderierter Login: direkt einloggen, kein TOTP-Schritt (IdP verantwortet MFA).
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function callbackUri(string $slug): string
    {
        return url("/auth/oidc/{$slug}/callback");
    }
}
```

- [ ] **Step 5: Routen** in `routes/auth.php` (`guest`-Gruppe):

```php
Route::get('auth/oidc/{slug}/redirect', [\App\Http\Controllers\Auth\OidcController::class, 'redirect'])
    ->name('oidc.redirect')->middleware('throttle:10,1');
Route::get('auth/oidc/{slug}/callback', [\App\Http\Controllers\Auth\OidcController::class, 'callback'])
    ->name('oidc.callback')->middleware('throttle:10,1');
```

- [ ] **Step 6:** Run → alle PASS; Pint + PHPStan.
- [ ] **Step 7:** Commit `feat: oidc login flow with identity linking and safe provisioning`.

---

### Task O6: Admin-GUI (Provider-Verwaltung + Discovery)

**Files:** `app/Http/Controllers/Admin/OidcProviderController.php`, `app/Http/Requests/Admin/StoreOidcProviderRequest.php`, `routes/web.php`, `resources/js/pages/admin/oidc/Index.vue`, Test `tests/Feature/Admin/OidcProviderAdminTest.php`.

- [ ] **Step 1: Failing test** — deckt ab: nur `admin` (nicht `maintainer`) darf; store legt Provider an (Secret nie in Props); ein „Discovery"-Action-Endpoint füllt Endpunkte aus einem gefakten `.well-known`.

```php
<?php

use App\Enums\UserRole;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
});

it('lists providers without exposing the secret', function () {
    OidcProvider::factory()->create(['name' => 'Authentik', 'client_secret' => 'shh']);
    $this->actingAs($this->admin)->get('/admin/oidc')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/oidc/Index')->has('providers', 1)
            ->where('providers.0.has_secret', true)->missing('providers.0.client_secret'));
});

it('forbids maintainers from managing providers', function () {
    $maint = User::factory()->for(Organization::factory())->create(['role' => UserRole::Maintainer]);
    $this->actingAs($maint)->get('/admin/oidc')->assertForbidden();
});

it('creates a provider', function () {
    $this->actingAs($this->admin)->post('/admin/oidc', [
        'name' => 'Keycloak', 'slug' => 'keycloak', 'client_id' => 'cid', 'client_secret' => 'sec',
        'issuer' => 'https://idp.test', 'authorization_endpoint' => 'https://idp.test/a',
        'token_endpoint' => 'https://idp.test/t', 'jwks_uri' => 'https://idp.test/j',
        'scopes' => 'openid email profile',
    ])->assertRedirect();
    expect(OidcProvider::where('slug', 'keycloak')->exists())->toBeTrue();
});

it('fills endpoints from discovery', function () {
    Http::fake(['https://idp.test/.well-known/openid-configuration' => Http::response([
        'issuer' => 'https://idp.test', 'authorization_endpoint' => 'https://idp.test/authorize',
        'token_endpoint' => 'https://idp.test/token', 'userinfo_endpoint' => 'https://idp.test/userinfo', 'jwks_uri' => 'https://idp.test/jwks',
    ])]);

    $this->actingAs($this->admin)->postJson('/admin/oidc/discover', ['issuer' => 'https://idp.test'])
        ->assertOk()->assertJsonPath('authorization_endpoint', 'https://idp.test/authorize');
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3:** `StoreOidcProviderRequest` (Validierung: `slug` unique + kebab, URLs `url`-validiert, `default_role` via `Rule::enum(UserRole::class)`, `client_secret` required beim Anlegen). Controller `OidcProviderController` mit `index/store/destroy` + `discover(Request)` (nutzt `OidcDiscovery`). `index` gibt `has_secret` statt Secret. Beim Update ohne neues Secret das alte behalten.

- [ ] **Step 4:** Routen in `routes/web.php` in **separater** Admin-Gruppe nur für `admin` (nicht `maintainer`), da IdP-Config hochsensibel:

```php
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('oidc', Admin\OidcProviderController::class)->only(['index', 'store', 'destroy'])->parameters(['oidc' => 'provider']);
    Route::post('oidc/discover', [Admin\OidcProviderController::class, 'discover'])->name('oidc.discover');
});
```

- [ ] **Step 5:** `resources/js/pages/admin/oidc/Index.vue` analog zu `resources/js/pages/admin/upstreams/Index.vue` (Liste + Anlege-Formular; „Aus Discovery laden"-Button ruft `admin.oidc.discover` und füllt die Endpunkt-Felder; Toggle enabled/allow_registration; Default-Org-Auswahl). Secret-Feld nur schreibend, nie angezeigt (`has_secret`-Badge).

- [ ] **Step 6:** Run → PASS; `ddev exec npm run build`; Pint + PHPStan.
- [ ] **Step 7:** Commit `feat: admin gui for oidc providers with discovery`.

---

### Task O7: Login-Buttons + Doku

**Files:** `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `resources/js/pages/auth/Login.vue`, `docs/oidc-setup.md`, Test-Ergänzung in `OidcLoginTest.php` optional.

- [ ] **Step 1:** `AuthenticatedSessionController@create` um `oidcProviders` erweitern:

```php
'oidcProviders' => \App\Models\OidcProvider::where('enabled', true)->orderBy('name')->get(['slug', 'name'])
    ->map(fn ($p) => ['slug' => $p->slug, 'name' => $p->name]),
```

- [ ] **Step 2:** `Login.vue` — über/unter dem Formular pro Provider einen Button „Mit {name} anmelden" (Link auf `route('oidc.redirect', provider.slug)`, normaler GET-Redirect, kein Inertia-`Link` mit XHR — nutze `<a :href="...">` oder `router.visit` mit external). Prop `oidcProviders: Array<{slug,name}>` (default []). Trenner „oder" nur zeigen, wenn Provider vorhanden sind.

- [ ] **Step 3:** `docs/oidc-setup.md` — Betreiber-Anleitung: benötigte Felder, die **redirect_uri** (`{app.url}/auth/oidc/{slug}/callback`) zum Eintragen im IdP, Discovery-Nutzung, und das **Sicherheitsmodell**: PKCE+state+nonce, id_token-JWKS-Verifikation, Verknüpfung nur über verifizierte E-Mail, Auto-Provisioning opt-in pro Provider mit Default-Org/Rolle, und dass ein OIDC-Login den TOTP-Schritt ersetzt (föderierte MFA — wie Passkeys).

- [ ] **Step 4:** `ddev exec npm run build`; betroffene Tests grün.
- [ ] **Step 5:** Commit `feat: sso login buttons and oidc operator documentation`.

---

### Task O8: E2E + volle Suite

- [ ] **Step 1:** Ein `tests/Feature/Auth/OidcEndToEndTest.php` das den Happy-Path zusammenfasst (redirect setzt Session → callback mit gefaktem IdP → eingeloggt → Identität verknüpft), plus einen Provisioning-Durchlauf. (Kann sich Fixtures aus O5 teilen bzw. dupliziert die IdP-Fake-Helper.)
- [ ] **Step 2: Volle Suite** `ddev exec vendor/bin/pest` → alle grün (Gesamtzahl melden). `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run build`.
- [ ] **Step 3:** Commit `test: end-to-end oidc sso login`.

---

## Self-Review

- **Spec §6 „OIDC/SSO über generischen, GUI-konfigurierbaren Adapter":** GUI-CRUD (O6), Discovery (O2/O6), generisch über beliebige OIDC-IdPs (O1–O5) ✓.
- **Sicherheit:** client_secret encrypted + `$hidden`; PKCE (S256) + state (CSRF) + nonce (Replay) (O3/O5); id_token-Signatur gegen JWKS/RS256 + iss/aud/exp/nonce (O4); SSRF-Schutz auf Discovery/Token/JWKS via `UrlSafety` + keine Redirects (O2/O4); Verknüpfung nur über **verifizierte** E-Mail (kein Account-Takeover); Auto-Provisioning opt-in mit Default-Org/Rolle; IdP-Config nur für `role:admin`; throttle auf redirect/callback.
- **Konsistenz:** OIDC-Login schließt direkt ab (föderierte MFA), analog zur v0.8-Passkey-Entscheidung — dokumentiert.
- **Verschoben/Follow-up:** JWKS-Caching (aktuell pro Login-Callback geladen — für Betrieb ggf. cachen mit kurzer TTL + Key-Rotation); userinfo_endpoint wird nicht zwingend abgefragt (Claims kommen aus dem id_token); Multi-Org-Zuordnung beim Provisioning (aktuell eine Default-Org je Provider); `trustProxies`-Follow-up aus v0.7 gilt weiter.

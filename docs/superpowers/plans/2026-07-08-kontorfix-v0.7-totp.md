# Kontorfix v0.7 – TOTP-Zweifaktor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nutzer können TOTP-Zweifaktor (Authenticator-App) per GUI aktivieren, bestätigen und deaktivieren; nach Aktivierung verlangt der Login nach Passwort einen zweiten Faktor (TOTP-Code oder einmaligen Recovery-Code).

**Architecture:** Aufbauend auf dem bestehenden Breeze-Inertia-Login (handgeschriebene Auth-Controller, kein Fortify). Ein `TwoFactorAuthenticator`-Service kapselt Secret-Erzeugung, Code-Verifikation und QR-SVG (über `pragmarx/google2fa-qrcode`). Drei neue verschlüsselte User-Spalten (`two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`) — konventionsgleich zu Laravel Fortify, damit später kompatibel. Der Login wird zweistufig: `AuthenticatedSessionController@store` validiert Credentials OHNE einzuloggen; hat der User bestätigte 2FA, wird die User-ID in die Session gelegt und auf eine Challenge-Seite umgeleitet, die den zweiten Faktor prüft und erst dann einloggt.

**Tech Stack:** Laravel 12, Inertia v2 + Vue 3, `pragmarx/google2fa-qrcode` (+ transitive `bacon/bacon-qr-code`), Pest, Pint, Larastan L6.

---

## File Structure

- Create migration `database/migrations/XXXX_add_two_factor_columns_to_users_table.php` — 3 Spalten.
- Modify `app/Models/User.php` — encrypted casts + Helfer (`hasEnabledTwoFactor`, `hasConfirmedTwoFactor`, `recoveryCodes`, `replaceRecoveryCode`).
- Create `app/Services/Auth/TwoFactorAuthenticator.php` — Secret, verify, QR-SVG, Recovery-Codes.
- Create `app/Http/Controllers/Settings/TwoFactorController.php` — show/enable/confirm/disable/regenerate.
- Create `app/Http/Requests/Settings/DisableTwoFactorRequest.php` — Passwort-Bestätigung.
- Create `app/Http/Controllers/Auth/TwoFactorChallengeController.php` — Challenge-Seite + Verifikation.
- Modify `app/Http/Controllers/Auth/AuthenticatedSessionController.php` — zweistufiger Login.
- Modify `app/Http/Requests/Auth/LoginRequest.php` — `validateCredentials()` (Rate-Limit + Auth::validate, kein Login).
- Modify `routes/settings.php` (2FA-Management) und `routes/auth.php` (Challenge).
- Create `resources/js/pages/settings/TwoFactor.vue`, `resources/js/pages/auth/TwoFactorChallenge.vue`.
- Modify `resources/js/layouts/settings/Layout.vue` — Nav-Link „Zwei-Faktor".
- Tests: `tests/Unit/TwoFactorAuthenticatorTest.php`, `tests/Feature/Settings/TwoFactorSetupTest.php`, `tests/Feature/Auth/TwoFactorChallengeTest.php`, `tests/Feature/Auth/TwoFactorLoginFlowTest.php`.

---

### Task L0: Paket installieren

**Files:** `composer.json`, `composer.lock`

- [ ] **Step 1: Paket hinzufügen**

Run: `ddev composer require pragmarx/google2fa-qrcode`
Erwartung: installiert `pragmarx/google2fa-qrcode`, `pragmarx/google2fa`, `bacon/bacon-qr-code`.

- [ ] **Step 2: Verifizieren**

Run: `ddev exec php -r "require 'vendor/autoload.php'; echo class_exists(\PragmaRX\Google2FAQRCode\Google2FA::class) ? 'OK' : 'MISSING';"`
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "build: add pragmarx/google2fa-qrcode for totp two-factor"
```

---

### Task L1: Schema + User-Model

**Files:**
- Create: `database/migrations/XXXX_add_two_factor_columns_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Unit/UserTwoFactorTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports two factor state and encrypts the secret at rest', function () {
    $user = User::factory()->create();
    expect($user->hasEnabledTwoFactor())->toBeFalse();
    expect($user->hasConfirmedTwoFactor())->toBeFalse();

    $user->forceFill([
        'two_factor_secret' => 'PLAINSECRET',
        'two_factor_recovery_codes' => ['aaaa-bbbb', 'cccc-dddd'],
    ])->save();

    expect($user->hasEnabledTwoFactor())->toBeTrue();
    expect($user->hasConfirmedTwoFactor())->toBeFalse(); // noch nicht bestätigt

    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue();

    // Recovery-Codes werden als Array gelesen und verschlüsselt gespeichert.
    expect($user->fresh()->recoveryCodes())->toBe(['aaaa-bbbb', 'cccc-dddd']);
    $raw = \DB::table('users')->where('id', $user->id)->value('two_factor_secret');
    expect($raw)->not->toBe('PLAINSECRET'); // Ciphertext, nicht Klartext

    // Recovery-Code-Verbrauch entfernt genau einen Code.
    $user->replaceRecoveryCode('aaaa-bbbb');
    expect($user->fresh()->recoveryCodes())->toBe(['cccc-dddd']);
});
```

- [ ] **Step 2: Run — expect fail**

Run: `ddev exec vendor/bin/pest tests/Unit/UserTwoFactorTest.php`
Expected: FAIL.

- [ ] **Step 3: Migration** — `ddev exec php artisan make:migration add_two_factor_columns_to_users_table`, dann `up()`:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->text('two_factor_secret')->nullable()->after('password');
        $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
        $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
    });
}
```

- [ ] **Step 4: User-Model** — casts erweitern und Helfer ergänzen:

```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => UserRole::class,
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
    ];
}

public function hasEnabledTwoFactor(): bool
{
    return ! is_null($this->two_factor_secret);
}

public function hasConfirmedTwoFactor(): bool
{
    return $this->hasEnabledTwoFactor() && ! is_null($this->two_factor_confirmed_at);
}

/** @return list<string> */
public function recoveryCodes(): array
{
    return $this->two_factor_recovery_codes ?? [];
}

/** Verbraucht (entfernt) genau einen Recovery-Code und speichert. */
public function replaceRecoveryCode(string $code): void
{
    $this->forceFill([
        'two_factor_recovery_codes' => array_values(array_filter(
            $this->recoveryCodes(),
            fn (string $c) => ! hash_equals($c, $code),
        )),
    ])->save();
}
```

Ergänze `two_factor_secret`, `two_factor_recovery_codes` NICHT in `$fillable` (bewusst nur via `forceFill`), und ergänze sie zu `$hidden`, damit sie nie in Inertia-Props/JSON landen:
```php
protected $hidden = [
    'password',
    'remember_token',
    'two_factor_secret',
    'two_factor_recovery_codes',
];
```

- [ ] **Step 5: Run — expect pass**

Run: `ddev exec vendor/bin/pest tests/Unit/UserTwoFactorTest.php` → PASS. Pint + PHPStan auf `app/Models/User.php`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/ app/Models/User.php tests/Unit/UserTwoFactorTest.php
git commit -m "feat: two-factor columns and helpers on user model"
```

---

### Task L2: TwoFactorAuthenticator-Service

**Files:**
- Create: `app/Services/Auth/TwoFactorAuthenticator.php`
- Test: `tests/Unit/TwoFactorAuthenticatorTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Services\Auth\TwoFactorAuthenticator;

it('generates a secret, verifies its current code and rejects a wrong one', function () {
    $svc = app(TwoFactorAuthenticator::class);
    $secret = $svc->generateSecret();

    expect($secret)->toBeString()->not->toBeEmpty();

    $current = $svc->currentCode($secret);
    expect($svc->verify($secret, $current))->toBeTrue();
    expect($svc->verify($secret, '000000'))->toBeFalse();
});

it('produces an inline svg qr code data uri', function () {
    $svc = app(TwoFactorAuthenticator::class);
    $secret = $svc->generateSecret();

    $qr = $svc->qrCodeDataUri('Kontorfix', 'user@example.test', $secret);
    expect($qr)->toStartWith('data:image/svg+xml');
});

it('generates eight unique recovery codes', function () {
    $codes = app(TwoFactorAuthenticator::class)->generateRecoveryCodes();
    expect($codes)->toHaveCount(8);
    expect(array_unique($codes))->toHaveCount(8);
});
```

- [ ] **Step 2: Run — expect fail**

Run: `ddev exec vendor/bin/pest tests/Unit/TwoFactorAuthenticatorTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Auth;

use PragmaRX\Google2FAQRCode\Google2FA;
use Illuminate\Support\Str;

class TwoFactorAuthenticator
{
    public function __construct(private Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /** Prüft einen 6-stelligen Code gegen das Secret (±1 Zeitfenster Toleranz). */
    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $code, 1);
    }

    /** Aktueller OTP-Code — nur für Tests / Debugging. */
    public function currentCode(string $secret): string
    {
        return $this->engine->getCurrentOtp($secret);
    }

    /** Inline-SVG-QR als data:-URI (kein externer Request, CSP-sicher). */
    public function qrCodeDataUri(string $company, string $holder, string $secret): string
    {
        $svg = $this->engine->getQRCodeInline($company, $holder, $secret);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Acht einmalige Recovery-Codes im Format xxxxxxxx-xxxxxxxx.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => Str::lower(Str::random(8).'-'.Str::random(8)))
            ->all();
    }
}
```

Hinweis zur Registrierung: `Google2FA` (aus `pragmarx/google2fa-qrcode`) hat einen parameterlosen Konstruktor und wird vom Container automatisch aufgelöst — keine Binding-Registrierung nötig. Falls `getQRCodeInline` einen QR-Backend-Fehler wirft (fehlendes `bacon/bacon-qr-code`), prüfe die Installation aus L0. `getQRCodeInline` liefert per Default ein SVG (kein imagick nötig).

- [ ] **Step 4: Run — expect pass**

Run: `ddev exec vendor/bin/pest tests/Unit/TwoFactorAuthenticatorTest.php` → PASS. Pint + PHPStan.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Auth/TwoFactorAuthenticator.php tests/Unit/TwoFactorAuthenticatorTest.php
git commit -m "feat: two-factor authenticator service (totp verify, qr, recovery codes)"
```

---

### Task L3: 2FA-Management (settings) — enable/confirm/disable

**Files:**
- Create: `app/Http/Controllers/Settings/TwoFactorController.php`
- Create: `app/Http/Requests/Settings/DisableTwoFactorRequest.php`
- Modify: `routes/settings.php`
- Test: `tests/Feature/Settings/TwoFactorSetupTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('enables (unconfirmed) then confirms two factor with a valid code', function () {
    // Aktivieren: erzeugt Secret + Recovery-Codes, noch unbestätigt.
    $this->actingAs($this->user)->post('/settings/two-factor/enable')->assertRedirect();

    $this->user->refresh();
    expect($this->user->hasEnabledTwoFactor())->toBeTrue();
    expect($this->user->hasConfirmedTwoFactor())->toBeFalse();
    expect($this->user->recoveryCodes())->toHaveCount(8);

    // Setup-Seite zeigt QR + Secret + Codes nur solange unbestätigt.
    $this->actingAs($this->user)->get('/settings/two-factor')
        ->assertInertia(fn ($p) => $p->component('settings/TwoFactor')
            ->where('confirmed', false)
            ->has('setup.qr')->has('setup.secret')->has('setup.recoveryCodes'));

    // Bestätigen mit gültigem Code.
    $code = app(TwoFactorAuthenticator::class)->currentCode($this->user->two_factor_secret);
    $this->actingAs($this->user)->post('/settings/two-factor/confirm', ['code' => $code])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($this->user->fresh()->hasConfirmedTwoFactor())->toBeTrue();

    // Nach Bestätigung wird das Secret nicht mehr ausgeliefert.
    $this->actingAs($this->user)->get('/settings/two-factor')
        ->assertInertia(fn ($p) => $p->where('confirmed', true)->where('setup', null));
});

it('rejects confirmation with a wrong code', function () {
    $this->actingAs($this->user)->post('/settings/two-factor/enable');
    $this->actingAs($this->user)->post('/settings/two-factor/confirm', ['code' => '000000'])
        ->assertSessionHasErrors('code');
    expect($this->user->fresh()->hasConfirmedTwoFactor())->toBeFalse();
});

it('disables two factor only with the correct password', function () {
    $this->actingAs($this->user)->post('/settings/two-factor/enable');
    $code = app(TwoFactorAuthenticator::class)->currentCode($this->user->fresh()->two_factor_secret);
    $this->actingAs($this->user)->post('/settings/two-factor/confirm', ['code' => $code]);

    // Falsches Passwort → abgelehnt.
    $this->actingAs($this->user)->delete('/settings/two-factor', ['password' => 'wrong'])
        ->assertSessionHasErrors('password');
    expect($this->user->fresh()->hasEnabledTwoFactor())->toBeTrue();

    // Richtiges Passwort (Factory-Default 'password') → deaktiviert, Spalten geleert.
    $this->actingAs($this->user)->delete('/settings/two-factor', ['password' => 'password'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $fresh = $this->user->fresh();
    expect($fresh->hasEnabledTwoFactor())->toBeFalse();
    expect($fresh->two_factor_confirmed_at)->toBeNull();
});
```

Prüfe den Factory-Passwort-Default in `database/factories/UserFactory.php` (Breeze-Default ist `'password'`). Falls abweichend, passe den Klartext im Test an — Assertions bleiben.

- [ ] **Step 2: Run — expect fail**

Run: `ddev exec vendor/bin/pest tests/Feature/Settings/TwoFactorSetupTest.php`
Expected: FAIL (Routen fehlen).

- [ ] **Step 3: DisableTwoFactorRequest**

```php
<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class DisableTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        // current_password prüft gegen das Passwort des eingeloggten Users.
        return ['password' => ['required', 'current_password']];
    }
}
```

- [ ] **Step 4: TwoFactorController**

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DisableTwoFactorRequest;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorAuthenticator $tfa) {}

    public function show(Request $request): Response
    {
        $user = $request->user();

        // Setup-Daten (QR/Secret/Codes) NUR ausliefern, solange aktiviert aber unbestätigt.
        $setup = null;
        if ($user->hasEnabledTwoFactor() && ! $user->hasConfirmedTwoFactor()) {
            $setup = [
                'qr' => $this->tfa->qrCodeDataUri(config('app.name'), $user->email, $user->two_factor_secret),
                'secret' => $user->two_factor_secret,
                'recoveryCodes' => $user->recoveryCodes(),
            ];
        }

        return Inertia::render('settings/TwoFactor', [
            'enabled' => $user->hasEnabledTwoFactor(),
            'confirmed' => $user->hasConfirmedTwoFactor(),
            'setup' => $setup,
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => $this->tfa->generateSecret(),
            'two_factor_recovery_codes' => $this->tfa->generateRecoveryCodes(),
            'two_factor_confirmed_at' => null,
        ])->save();

        return back();
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->hasEnabledTwoFactor() || ! $this->tfa->verify($user->two_factor_secret, $request->string('code'))) {
            throw ValidationException::withMessages(['code' => __('Der Code ist ungültig.')]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return back()->with('success', 'Zwei-Faktor-Authentifizierung aktiviert.');
    }

    public function disable(DisableTwoFactorRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return back()->with('success', 'Zwei-Faktor-Authentifizierung deaktiviert.');
    }
}
```

- [ ] **Step 5: Routen** in `routes/settings.php` (in der `auth`-Gruppe):

```php
Route::get('settings/two-factor', [\App\Http\Controllers\Settings\TwoFactorController::class, 'show'])->name('two-factor.show');
Route::post('settings/two-factor/enable', [\App\Http\Controllers\Settings\TwoFactorController::class, 'enable'])->name('two-factor.enable');
Route::post('settings/two-factor/confirm', [\App\Http\Controllers\Settings\TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
Route::delete('settings/two-factor', [\App\Http\Controllers\Settings\TwoFactorController::class, 'disable'])->name('two-factor.disable');
```
(Pint zieht die FQN ggf. in `use`-Statements — ok.)

- [ ] **Step 6: Run — expect pass**

Run: `ddev exec vendor/bin/pest tests/Feature/Settings/TwoFactorSetupTest.php` → alle PASS. Pint + PHPStan.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Settings/TwoFactorController.php app/Http/Requests/Settings/DisableTwoFactorRequest.php routes/settings.php tests/Feature/Settings/TwoFactorSetupTest.php
git commit -m "feat: two-factor enrollment, confirmation and disable in settings"
```

---

### Task L4: Zweistufiger Login + Challenge

**Files:**
- Modify: `app/Http/Requests/Auth/LoginRequest.php`
- Modify: `app/Http/Controllers/Auth/AuthenticatedSessionController.php`
- Create: `app/Http/Controllers/Auth/TwoFactorChallengeController.php`
- Modify: `routes/auth.php`
- Test: `tests/Feature/Auth/TwoFactorChallengeTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;

function enrolledUser(): User
{
    $user = User::factory()->create();
    $tfa = app(TwoFactorAuthenticator::class);
    $user->forceFill([
        'two_factor_secret' => $tfa->generateSecret(),
        'two_factor_recovery_codes' => ['keepme-keepme', 'useme00-useme00'],
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

it('does not log a 2fa user in on password; it redirects to the challenge', function () {
    $user = enrolledUser();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id);
});

it('completes login with a valid totp code', function () {
    $user = enrolledUser();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $code = app(TwoFactorAuthenticator::class)->currentCode($user->two_factor_secret);
    $this->post('/two-factor-challenge', ['code' => $code])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(session('login.id'))->toBeNull();
});

it('completes login with a recovery code and consumes it', function () {
    $user = enrolledUser();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->post('/two-factor-challenge', ['recovery_code' => 'useme00-useme00'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->recoveryCodes())->toBe(['keepme-keepme']); // verbraucht
});

it('rejects an invalid code and stays on the challenge', function () {
    $user = enrolledUser();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->from(route('two-factor.login'))
        ->post('/two-factor-challenge', ['code' => '000000'])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('redirects to login when hitting the challenge without a pending login', function () {
    $this->get('/two-factor-challenge')->assertRedirect(route('login'));
});

it('logs a normal user in directly without a challenge', function () {
    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});
```

- [ ] **Step 2: Run — expect fail**

Run: `ddev exec vendor/bin/pest tests/Feature/Auth/TwoFactorChallengeTest.php`
Expected: FAIL.

- [ ] **Step 3: LoginRequest** — `authenticate()` NICHT mehr einloggen lassen; stattdessen eine Methode, die Credentials validiert (mit Rate-Limit) und den User zurückgibt. Ersetze die `authenticate()`-Methode durch:

```php
/**
 * Prüft die Credentials MIT Rate-Limit, loggt aber NICHT ein.
 *
 * @throws ValidationException
 */
public function validateCredentials(): \App\Models\User
{
    $this->ensureIsNotRateLimited();

    /** @var \App\Models\User|null $user */
    $user = \App\Models\User::where('email', $this->string('email'))->first();

    if (! $user || ! \Illuminate\Support\Facades\Hash::check((string) $this->string('password'), $user->password)) {
        RateLimiter::hit($this->throttleKey());

        throw ValidationException::withMessages(['email' => trans('auth.failed')]);
    }

    RateLimiter::clear($this->throttleKey());

    return $user;
}
```
(Die Methoden `ensureIsNotRateLimited()` und `throttleKey()` bleiben unverändert. `authenticate()` darf entfernt werden, da nur noch der Controller die neue Methode nutzt.)

- [ ] **Step 4: AuthenticatedSessionController@store** ersetzen:

```php
public function store(LoginRequest $request): RedirectResponse
{
    $user = $request->validateCredentials();

    // Bestätigte 2FA ⇒ noch nicht einloggen, sondern Challenge anstoßen.
    if ($user->hasConfirmedTwoFactor()) {
        $request->session()->put('login.id', $user->id);
        $request->session()->put('login.remember', $request->boolean('remember'));

        return redirect()->route('two-factor.login');
    }

    Auth::login($user, $request->boolean('remember'));
    $request->session()->regenerate();

    return redirect()->intended(route('dashboard', absolute: false));
}
```
Ergänze `use App\Models\User;` falls nötig (nur falls im Controller referenziert — hier nicht zwingend).

- [ ] **Step 5: TwoFactorChallengeController**

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function __construct(private TwoFactorAuthenticator $tfa) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/TwoFactorChallenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('login.id');
        if ($userId === null) {
            return redirect()->route('login');
        }

        /** @var User $user */
        $user = User::findOrFail($userId);

        $valid = false;
        if ($request->filled('code')) {
            $valid = $this->tfa->verify($user->two_factor_secret, $request->string('code'));
            $field = 'code';
        } elseif ($request->filled('recovery_code')) {
            $field = 'recovery_code';
            $submitted = (string) $request->string('recovery_code');
            if (in_array($submitted, $user->recoveryCodes(), true)) {
                $user->replaceRecoveryCode($submitted);
                $valid = true;
            }
        } else {
            $field = 'code';
        }

        if (! $valid) {
            throw ValidationException::withMessages([$field => __('Der Code ist ungültig.')]);
        }

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
```

- [ ] **Step 6: Routen** in `routes/auth.php` in der `guest`-Gruppe (ein noch-nicht-eingeloggter Mid-Login-User ist `guest`):

```php
Route::get('two-factor-challenge', [\App\Http\Controllers\Auth\TwoFactorChallengeController::class, 'create'])
    ->name('two-factor.login');
Route::post('two-factor-challenge', [\App\Http\Controllers\Auth\TwoFactorChallengeController::class, 'store'])
    ->middleware('throttle:6,1');
```

- [ ] **Step 7: Run — expect pass**

Run: `ddev exec vendor/bin/pest tests/Feature/Auth/TwoFactorChallengeTest.php` → alle PASS. Danach `ddev exec vendor/bin/pest tests/Feature/Auth/` (bestehende Auth-Tests dürfen NICHT brechen — besonders vorhandene Login-Tests, die evtl. `authenticate()` erwarteten). Pint + PHPStan.

Falls ein bestehender Auth-Test `authenticate()` direkt aufruft oder auf altes Verhalten prüft, prüfe ihn: das erwartete Verhalten (normaler User loggt direkt ein) ist unverändert — nur der interne Pfad änderte sich. Passe NUR echte Test-Referenzen auf die entfernte Methode an, nicht die Erwartungen.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Auth/LoginRequest.php app/Http/Controllers/Auth/AuthenticatedSessionController.php app/Http/Controllers/Auth/TwoFactorChallengeController.php routes/auth.php tests/Feature/Auth/TwoFactorChallengeTest.php
git commit -m "feat: two-step login with totp challenge and recovery codes"
```

---

### Task L5: Vue-Seiten + Settings-Nav

**Files:**
- Create: `resources/js/pages/settings/TwoFactor.vue`
- Create: `resources/js/pages/auth/TwoFactorChallenge.vue`
- Modify: `resources/js/layouts/settings/Layout.vue`

- [ ] **Step 1: `settings/TwoFactor.vue`** — Sieh dir `resources/js/pages/settings/Password.vue` für Layout/Imports (Settings-`Layout`, `Head`, shadcn-Inputs/Buttons, `useForm`) an. Zustände:
  - `enabled=false`: Button „Aktivieren" → `router.post(route('two-factor.enable'))`.
  - `enabled=true && confirmed=false`: QR-Bild (`<img :src="setup.qr">`), Secret als Text, Recovery-Codes-Liste (mit Hinweis „sicher aufbewahren, wird nur jetzt gezeigt"), Code-Eingabefeld + „Bestätigen" → `route('two-factor.confirm')`.
  - `confirmed=true`: Status „aktiv" + „Deaktivieren"-Formular mit Passwortfeld → `router.delete(route('two-factor.disable'), { data: { password } })`.
  Props: `enabled: boolean`, `confirmed: boolean`, `setup: { qr: string, secret: string, recoveryCodes: string[] } | null`.

- [ ] **Step 2: `auth/TwoFactorChallenge.vue`** — Sieh dir `resources/js/pages/auth/Login.vue` für den Auth-Layout-Rahmen an. Ein Code-Eingabefeld (`code`), Umschalter „Recovery-Code verwenden" der stattdessen ein `recovery_code`-Feld zeigt; Submit → `route('two-factor.login')` (POST). Nutze `useForm({ code: '', recovery_code: '' })` und sende nur das befüllte Feld. Zeige Validierungsfehler für `code`/`recovery_code`.

- [ ] **Step 3: Settings-Nav** — in `resources/js/layouts/settings/Layout.vue` einen Nav-Eintrag „Zwei-Faktor" → `route('two-factor.show')` analog zu den bestehenden Einträgen (Profil, Passwort, Darstellung) ergänzen.

- [ ] **Step 4: Build**

Run: `ddev exec npm run build` → ohne Fehler.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/settings/TwoFactor.vue resources/js/pages/auth/TwoFactorChallenge.vue resources/js/layouts/settings/Layout.vue
git commit -m "feat: two-factor settings and login challenge ui"
```

---

### Task L6: E2E-Gesamtflow

**Files:** `tests/Feature/Auth/TwoFactorLoginFlowTest.php`

- [ ] **Step 1: Test** — vollständiger Durchlauf über HTTP: einrichten → bestätigen → ausloggen → Login mit Passwort landet auf Challenge → Challenge mit TOTP-Code → eingeloggt; separat der Recovery-Pfad; und dass Deaktivieren den Login wieder einstufig macht.

```php
<?php

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;

it('runs the full enroll -> logout -> challenge -> login lifecycle', function () {
    $tfa = app(TwoFactorAuthenticator::class);
    $user = User::factory()->create();

    // Einrichten + bestätigen
    $this->actingAs($user)->post('/settings/two-factor/enable');
    $secret = $user->fresh()->two_factor_secret;
    $this->actingAs($user)->post('/settings/two-factor/confirm', ['code' => $tfa->currentCode($secret)])
        ->assertSessionHasNoErrors();
    expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue();

    // Ausloggen
    $this->post('/logout');
    $this->assertGuest();

    // Login mit Passwort ⇒ Challenge, noch nicht eingeloggt
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    // Challenge mit gültigem TOTP ⇒ eingeloggt
    $this->post('/two-factor-challenge', ['code' => $tfa->currentCode($secret)])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);

    // Deaktivieren ⇒ Login wieder einstufig
    $this->actingAs($user)->delete('/settings/two-factor', ['password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});
```

- [ ] **Step 2: Volle Suite**

Run: `ddev exec vendor/bin/pest` → alle grün (melde Gesamtzahl). `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run build`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Auth/TwoFactorLoginFlowTest.php
git commit -m "test: end-to-end totp two-factor login lifecycle"
```

---

## Self-Review

- **Spec §6 „E-Mail/Passwort + TOTP-Zweifaktor":** Enrollment (L3), zweistufiger Login (L4), GUI (L5) ✓.
- **Sicherheit:** Secret + Recovery-Codes verschlüsselt at rest (L1) und in `$hidden` (nie in Props); Challenge rate-limited (L4); Recovery-Codes einmalig (`replaceRecoveryCode`, `hash_equals`); Disable erfordert aktuelles Passwort (L3); TOTP-Verifikation mit ±1 Fenster; kein Login vor bestandenem zweiten Faktor.
- **Kein Leak:** Setup-Daten (QR/Secret/Codes) werden nur im unbestätigten Zustand ausgeliefert; nach Bestätigung `setup=null`.
- **Verschoben:** Passkeys/WebAuthn → v0.8, OIDC/SSO → v0.9 (eigene Pläne). „Trusted devices"/Remember-2FA bewusst YAGNI. Recovery-Code-Anzeige nach Bestätigung (Regenerate mit Passwortschutz) als optionales Follow-up notiert.

# Kontorfix v0.8 – Passkeys (WebAuthn) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nutzer können einen Passkey (WebAuthn) per GUI registrieren und sich damit passwortlos einloggen; Passkeys sind in den Settings verwaltbar (Liste, Löschen, geschützt durch Passwort-Bestätigung).

**Architecture:** Nutzt das offizielle `laravel/passkeys` (v0.2), das Backend, Routen, Zeremonien-Actions und Inertia-taugliche Redirect-Responses fertig mitbringt. Wir liefern: die Anbindung von `User` an den `PasskeyUser`-Contract, das Publizieren von Config/Migration, eine geschützte Settings-Seite (`/settings/passkeys`), einen Passkey-Login-Button auf der Login-Seite und die Browser-Zeremonie-Glue via `@simplewebauthn/browser`. Passkeys gelten für den App-Login (Betreiber-/Kunden-Portal), NICHT für die Composer/npm-Registry-Endpunkte.

**Tech Stack:** Laravel 12, `laravel/passkeys` + `web-auth/webauthn-lib`, Inertia v2 + Vue 3, `@simplewebauthn/browser`, Pest, Pint, Larastan L6.

**Testbarkeit (wichtig):** Die kryptografische WebAuthn-Zeremonie (attestation/assertion) lässt sich ohne echten Authenticator bzw. virtuellen Browser nicht deterministisch in Pest testen — dafür ist die offizielle Lib mit ihren Upstream-Tests zuständig. Unsere automatisierten Tests decken **Verdrahtung, Autorisierung und UI-Props** ab; die vollständige Zeremonie wird per **manuellem Smoke-Test** über HTTPS/localhost verifiziert (Q5).

---

## File Structure

- Modify `composer.json`/`composer.lock` — `laravel/passkeys` (bereits installiert, nur committen).
- Add npm `@simplewebauthn/browser`.
- Publish `config/passkeys.php` + Migration nach `database/migrations/`.
- Modify `app/Models/User.php` — `implements PasskeyUser` + `use PasskeyAuthenticatable`.
- Create `app/Http/Controllers/Settings/PasskeyController.php` — Inertia-Settings-Seite (Liste).
- Modify `routes/settings.php` — `/settings/passkeys` (auth + password.confirm).
- Create `resources/js/pages/settings/Passkeys.vue` — Liste + Registrieren + Löschen.
- Modify `resources/js/pages/auth/Login.vue` — „Mit Passkey anmelden"-Button.
- Modify `resources/js/layouts/settings/Layout.vue` — Nav-Link „Passkeys".
- Create `resources/js/lib/passkeys.ts` — Browser-Zeremonie-Helfer (Register/Login).
- Tests: `tests/Feature/Auth/PasskeyWiringTest.php`, `tests/Feature/Settings/PasskeyManagementTest.php`.

---

### Task Q0: Pakete verankern

`laravel/passkeys` wurde bereits via `ddev composer require laravel/passkeys` installiert (v0.2.1, zieht `web-auth/webauthn-lib` 5.3). Diese Aufgabe committet das und fügt die Browser-Bibliothek hinzu.

**Files:** `composer.json`, `composer.lock`, `package.json`, `package-lock.json`

- [ ] **Step 1: Browser-Lib installieren**

Run: `ddev exec npm install @simplewebauthn/browser`
Erwartung: fügt `@simplewebauthn/browser` zu `package.json` hinzu.

- [ ] **Step 2: Verifizieren**

Run: `ddev exec php -r "require 'vendor/autoload.php'; echo class_exists(\Laravel\Passkeys\Passkeys::class) ? 'OK' : 'MISSING';"`
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock package.json package-lock.json
git commit -m "build: add laravel/passkeys and @simplewebauthn/browser"
```

---

### Task Q1: Config/Migration publizieren + User anbinden

**Files:**
- Publish: `config/passkeys.php`, `database/migrations/XXXX_create_passkeys_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Auth/PasskeyWiringTest.php`

- [ ] **Step 1: Publizieren + migrieren**

```
ddev exec php artisan vendor:publish --tag=passkeys-config
ddev exec php artisan vendor:publish --tag=passkeys-migrations
ddev exec php artisan migrate
```
Die Migration nutzt `foreignIdFor(User::class, 'user_id')` — da `User` `HasUuids` verwendet, entsteht automatisch eine `foreignUuid`-Spalte (passt zu unseren UUID-Nutzern; keine Migration-Anpassung nötig). Verifiziere nach dem Migrate mit `ddev exec php artisan migrate:status`, dass die passkeys-Migration lief.

- [ ] **Step 2: Failing test** `tests/Feature/Auth/PasskeyWiringTest.php`

```php
<?php

use App\Models\User;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;

it('makes the user a passkey user with a passkeys relation', function () {
    $user = User::factory()->create();

    expect($user)->toBeInstanceOf(PasskeyUser::class);
    expect($user->hasPasskeysEnabled())->toBeFalse();

    Passkey::forceCreate([
        'user_id' => $user->getKey(),
        'name' => 'MacBook',
        'credential_id' => 'cred-'.$user->getKey(),
        'credential' => ['foo' => 'bar'],
    ]);

    expect($user->fresh()->hasPasskeysEnabled())->toBeTrue();
    expect($user->fresh()->passkeys)->toHaveCount(1);
});

it('derives a stable, non-pii user handle', function () {
    $user = User::factory()->create();

    $handle = $user->getPasskeyUserHandle();
    expect($handle)->toBe($user->fresh()->getPasskeyUserHandle()); // stabil
    expect($handle)->not->toContain($user->email); // keine PII
});

it('registers the package passkey routes', function () {
    expect(route('passkey.login-options'))->toContain('/passkeys/login/options');
    expect(route('passkey.login'))->toContain('/passkeys/login');
    expect(route('passkey.registration-options'))->toContain('/user/passkeys/options');
});
```

- [ ] **Step 3: Run — expect fail**

Run: `ddev exec vendor/bin/pest tests/Feature/Auth/PasskeyWiringTest.php`
Expected: FAIL (User implementiert Contract noch nicht).

- [ ] **Step 4: User anbinden** — in `app/Models/User.php`:
  - Imports: `use Laravel\Passkeys\Contracts\PasskeyUser;` und `use Laravel\Passkeys\PasskeyAuthenticatable;`
  - Klassensignatur: `class User extends Authenticatable implements PasskeyUser`
  - Im Trait-`use`: `PasskeyAuthenticatable` ergänzen (neben HasFactory, HasUuids, Notifiable).

Der Trait liefert `passkeys()`, `hasPasskeysEnabled()`, `getPasskeyUserHandle()`, `getPasskeyDisplayName()`, `getPasskeyUsername()` — keine weitere Methode nötig.

- [ ] **Step 5: Run — expect pass**

Run: `ddev exec vendor/bin/pest tests/Feature/Auth/PasskeyWiringTest.php` → PASS. Pint + PHPStan auf `app/Models/User.php`.

Falls PHPStan die `passkeys`-Relation/`@property` bemängelt, ergänze `@property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Passkeys\Passkey> $passkeys` am Klassenkopf.

- [ ] **Step 6: Commit**

```bash
git add config/passkeys.php database/migrations/ app/Models/User.php tests/Feature/Auth/PasskeyWiringTest.php
git commit -m "feat: wire user model to passkey authentication"
```

---

### Task Q2: Passkey-Verwaltung (Settings-Seite + Autorisierung)

Die Paket-Routen (`passkey.registration-options`, `passkey.store`, `passkey.destroy`) existieren bereits und sind mit `auth` + `password.confirm` geschützt. Diese Aufgabe liefert die Inertia-Settings-Seite mit der Passkey-Liste des Nutzers und bestätigt die Besitzer-Autorisierung des Löschens.

**Files:**
- Create: `app/Http/Controllers/Settings/PasskeyController.php`
- Modify: `routes/settings.php`
- Test: `tests/Feature/Settings/PasskeyManagementTest.php`

- [ ] **Step 1: Failing test** `tests/Feature/Settings/PasskeyManagementTest.php`

```php
<?php

use App\Models\User;
use Laravel\Passkeys\Passkey;

function makePasskey(User $user, string $name = 'Key'): Passkey
{
    return Passkey::forceCreate([
        'user_id' => $user->getKey(),
        'name' => $name,
        'credential_id' => 'cred-'.$user->getKey().'-'.$name,
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
}

it('shows the settings page with the users own passkeys', function () {
    $user = User::factory()->create();
    makePasskey($user, 'MacBook');
    makePasskey(User::factory()->create(), 'Someone else');

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get('/settings/passkeys')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('settings/Passkeys')
            ->has('passkeys', 1)
            ->where('passkeys.0.name', 'MacBook'));
});

it('requires password confirmation to view the passkey settings', function () {
    $user = User::factory()->create();

    // Ohne bestätigtes Passwort leitet die confirm-Middleware um.
    $this->actingAs($user)->get('/settings/passkeys')->assertRedirect(route('password.confirm'));
});

it('lets a user delete only their own passkey', function () {
    $user = User::factory()->create();
    $own = makePasskey($user, 'Mine');
    $foreign = makePasskey(User::factory()->create(), 'Theirs');

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->delete("/user/passkeys/{$foreign->id}")->assertForbidden();
    expect(Passkey::find($foreign->id))->not->toBeNull();

    $this->delete("/user/passkeys/{$own->id}");
    expect(Passkey::find($own->id))->toBeNull();
});
```

- [ ] **Step 2: Run — expect fail**

Run: `ddev exec vendor/bin/pest tests/Feature/Settings/PasskeyManagementTest.php`
Expected: FAIL (Settings-Route fehlt). Hinweis: der Delete-Test nutzt die Paket-Route und sollte schon grün sein, sobald password_confirmed gesetzt ist — falls nicht, prüfe, ob die Paket-Routen geladen sind.

- [ ] **Step 3: Controller** `app/Http/Controllers/Settings/PasskeyController.php`:

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passkeys\Passkey;

class PasskeyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/Passkeys', [
            'passkeys' => $request->user()->passkeys()->latest()->get()
                ->map(fn (Passkey $p) => [
                    'id' => (string) $p->id,
                    'name' => $p->name,
                    'authenticator' => $p->authenticator,
                    'last_used_at' => $p->last_used_at?->diffForHumans(),
                    'created_at' => $p->created_at?->toDateString(),
                ]),
        ]);
    }
}
```

- [ ] **Step 4: Route** in `routes/settings.php` in der `auth`-Gruppe, zusätzlich mit `password.confirm`:

```php
Route::get('settings/passkeys', [\App\Http\Controllers\Settings\PasskeyController::class, 'index'])
    ->middleware('password.confirm')
    ->name('passkeys.show');
```
(Pint zieht die FQN ggf. in ein `use` — ok.)

- [ ] **Step 5: Run — expect pass**

Run: `ddev exec vendor/bin/pest tests/Feature/Settings/PasskeyManagementTest.php` → alle PASS. Pint + PHPStan.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Settings/PasskeyController.php routes/settings.php tests/Feature/Settings/PasskeyManagementTest.php
git commit -m "feat: passkey management settings page with owner-scoped deletion"
```

---

### Task Q3: Browser-Zeremonie-Helfer

Kapselt die WebAuthn-Zeremonie im Frontend: Optionen holen (axios), `@simplewebauthn/browser` ausführen, Credential posten. Rein clientseitig, kein PHP-Test — Verifikation über den Build und den manuellen Smoke-Test (Q5).

**Files:**
- Create: `resources/js/lib/passkeys.ts`

- [ ] **Step 1: Vor dem Schreiben** die Feld-Namen der Registrierungs-Request verifizieren: lies `vendor/laravel/passkeys/src/Http/Requests/PasskeyRegistrationRequest.php` und bestätige, dass `store` `name` und `credential` erwartet (Verification-Request nutzt `credential` + optional `remember`). Passe die Payload-Keys unten exakt daran an.

- [ ] **Step 2: Implement** `resources/js/lib/passkeys.ts`:

```ts
import axios from 'axios'
import { startAuthentication, startRegistration } from '@simplewebauthn/browser'

/** Registriert einen neuen Passkey für den eingeloggten Nutzer. */
export async function registerPasskey(name: string): Promise<void> {
    const { data } = await axios.get(route('passkey.registration-options'))
    const attestation = await startRegistration({ optionsJSON: data.options })
    await axios.post(route('passkey.store'), { name, credential: attestation })
}

/** Meldet passwortlos per Passkey an; gibt das Weiterleitungsziel zurück. */
export async function loginWithPasskey(remember: boolean): Promise<string> {
    const { data: opts } = await axios.get(route('passkey.login-options'))
    const assertion = await startAuthentication({ optionsJSON: opts.options })
    const { data } = await axios.post(route('passkey.login'), { credential: assertion, remember })
    return data.redirect as string
}

/** WebAuthn im Browser verfügbar? */
export function passkeysSupported(): boolean {
    return typeof window !== 'undefined' && !!window.PublicKeyCredential
}
```

Falls `route()` (Ziggy) in `.ts`-Modulen nicht global typisiert ist, nutze das im Repo etablierte Muster (ggf. `import { route } from 'ziggy-js'` oder `declare const route`). Prüfe, wie andere `.ts`-Helfer im Repo `route()` verwenden.

- [ ] **Step 3: Build**

Run: `ddev exec npm run build` → ohne Fehler.

- [ ] **Step 4: Commit**

```bash
git add resources/js/lib/passkeys.ts
git commit -m "feat: browser webauthn ceremony helpers"
```

---

### Task Q4: Vue-UI (Settings-Liste + Login-Button + Nav)

**Files:**
- Create: `resources/js/pages/settings/Passkeys.vue`
- Modify: `resources/js/pages/auth/Login.vue`
- Modify: `resources/js/layouts/settings/Layout.vue`

- [ ] **Step 1: `settings/Passkeys.vue`** — Muster: `resources/js/pages/settings/Password.vue` (AppLayout + Settings-Layout, `Head`, `HeadingSmall`, shadcn `Input`/`Button`, `InputError`). Props: `passkeys: Array<{id,name,authenticator,last_used_at,created_at}>`.
  - „Passkey hinzufügen": Eingabefeld Name + Button, ruft `registerPasskey(name)` aus `@/lib/passkeys`; bei Erfolg `router.reload({ only: ['passkeys'] })`; Fehler abfangen und anzeigen (z.B. Nutzer bricht ab → Meldung „Registrierung abgebrochen").
  - Liste der Passkeys (Name, Authenticator, zuletzt genutzt, erstellt) mit Löschen-Button → `router.delete(route('passkey.destroy', p.id), { preserveScroll: true })`.
  - Hinweis-Text neutral/deutsch.

- [ ] **Step 2: `auth/Login.vue`** — unter dem Login-Button einen sekundären Button „Mit Passkey anmelden" ergänzen. `onClick`: `const url = await loginWithPasskey(form.remember); window.location.href = url`. Fehler (Abbruch/kein Passkey) in einer lokalen `ref` anzeigen. Button nur zeigen, wenn `passkeysSupported()`. Import aus `@/lib/passkeys`. Die bestehende Passwort-Form unverändert lassen.

- [ ] **Step 3: Settings-Nav** — in `resources/js/layouts/settings/Layout.vue` `{ title: 'Passkeys', href: '/settings/passkeys' }` ergänzen (statischer href, wie die anderen Einträge).

- [ ] **Step 4: Build + Lint**

Run: `ddev exec npm run build` → ohne Fehler. Falls vorhanden: `ddev exec npm run lint`.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/settings/Passkeys.vue resources/js/pages/auth/Login.vue resources/js/layouts/settings/Layout.vue
git commit -m "feat: passkey settings ui and passwordless login button"
```

---

### Task Q5: Volle Suite + manueller Smoke-Test-Hinweis

**Files:** keine Code-Änderung; ggf. kleine README-/Docs-Notiz.

- [ ] **Step 1: Volle Suite**

Run: `ddev exec vendor/bin/pest` → ALLE grün (Gesamtzahl melden). `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run build`.

- [ ] **Step 2: Manueller Smoke-Test dokumentieren** — in der Projekt-Doku (oder als Kommentar in `config/passkeys.php`) festhalten, wie der End-to-End-Passkey-Flow manuell zu verifizieren ist: über die DDEV-HTTPS-URL (WebAuthn braucht `https` oder `localhost`) einloggen, unter Settings → Passkeys einen Passkey registrieren (Touch-ID/virtueller Authenticator), ausloggen, „Mit Passkey anmelden" testen. `relying_party_id`/`allowed_origins` müssen zur genutzten Domain passen (Default = `app.url`).

- [ ] **Step 3: Commit** (falls Doku-Notiz)

```bash
git add -A
git commit -m "docs: manual passkey smoke-test procedure"
```

---

## Self-Review

- **Spec §6 „Passkeys (WebAuthn)":** Registrierung (Q2/Q4), passwortloser Login (Q3/Q4), GUI-Verwaltung (Q2/Q4) ✓.
- **Sicherheit:** Verwaltung hinter `auth` + `password.confirm`; Löschen besitzer-scoped (Paket erzwingt `abort_unless($passkey->user_id === $user->getKey(), 403)`, plus eigener Test); RP-ID/Origins aus `app.url`; Passkeys gelten nur für den App-Login, nicht für Registry-Endpunkte; nur „none"-Attestation (kein Hardware-Tracking).
- **Testgrenze bewusst:** Krypto-Zeremonie nicht in Pest automatisiert (Lib-Verantwortung) — Wiring/Autorisierung/UI getestet, Zeremonie per manuellem Smoke-Test (Q5). Klar dokumentiert.
- **Verschoben:** OIDC/SSO → v0.9; virtueller-Authenticator-E2E (Playwright) als optionales Follow-up; Passkey als zweiter Faktor / Login-Verknüpfung mit 2FA bewusst YAGNI.

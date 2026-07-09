# Kontorfix v2.5 – Nutzer-Einladung per E-Mail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Beim Anlegen eines Nutzers kann der Betreiber eine **Einladung per E-Mail** verschicken (Set-Passwort-Link) statt ein Klartext-Passwort selbst zu vergeben; der Eingeladene setzt sein Passwort selbst. Ein „Einladung erneut senden" pro Nutzer.

**Architecture:** Wiederverwendung der vorhandenen Passwort-Reset-Infrastruktur (Broker `password_reset_tokens`, Routen `password.reset`/`password.store`, `NewPasswordController`). Beim Einladen wird der User mit einem zufälligen (unbrauchbaren) Passwort angelegt und eine `UserInvitation`-Notification mit einem Set-Passwort-Link (Broker-Token → `password.reset`) versendet. Passwort im Anlage-Request wird optional: ist eins gesetzt → direkt (bisheriges Verhalten); fehlt es → Einladung. Alles unter `role:admin` (operator).

**Tech Stack:** Laravel 12 (Notifications, Password Broker), Inertia v2 + Vue 3, Pest (`Notification::fake`), Pint, Larastan L6.

---

## File Structure

- Create `app/Notifications/UserInvitation.php`.
- Modify `app/Http/Controllers/Admin/UserController.php` (`store` optional Passwort/Invite; neue `invite`-Action zum erneuten Senden) + `app/Http/Requests/Admin/StoreUserRequest.php` (`password` nullable).
- Modify `routes/web.php` — `users.invite` in der `role:admin`-Gruppe.
- Modify `resources/js/pages/admin/users/Index.vue` — Umschalter „Passwort setzen"/„Einladen" + „Einladung erneut senden".
- Tests: `tests/Feature/Admin/UserInvitationTest.php`, E2E.

---

### Task IV1: Einladung beim Anlegen + Notification + erneut senden

**Files:** `app/Notifications/UserInvitation.php`, `app/Http/Controllers/Admin/UserController.php`, `app/Http/Requests/Admin/StoreUserRequest.php`, `routes/web.php`, Test `tests/Feature/Admin/UserInvitationTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
});

it('sends an invitation when no password is given', function () {
    Notification::fake();
    $cust = Organization::factory()->create();

    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'Neu', 'email' => 'neu@kunde.test', 'organization_id' => $cust->id, 'role' => 'member',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $user = User::where('email', 'neu@kunde.test')->firstOrFail();
    Notification::assertSentTo($user, UserInvitation::class);
});

it('sets the password directly when one is given (no invitation)', function () {
    Notification::fake();
    $cust = Organization::factory()->create();

    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'Direkt', 'email' => 'direkt@kunde.test', 'organization_id' => $cust->id, 'role' => 'member', 'password' => 'geheim-1234',
    ])->assertRedirect();

    $user = User::where('email', 'direkt@kunde.test')->firstOrFail();
    expect(\Illuminate\Support\Facades\Hash::check('geheim-1234', $user->password))->toBeTrue();
    Notification::assertNothingSentTo($user);
});

it('can resend an invitation to an existing user', function () {
    Notification::fake();
    $user = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);

    $this->actingAs($this->admin)->post("/admin/users/{$user->id}/invite")->assertRedirect();
    Notification::assertSentTo($user, UserInvitation::class);
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: `UserInvitation`-Notification**
```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

class UserInvitation extends Notification
{
    use Queueable;

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Set-Passwort-Link über den Passwort-Reset-Broker (zeitlich begrenzt, einmalig).
        $token = Password::broker()->createToken($notifiable);
        $url = url(route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false));

        return (new MailMessage)
            ->subject('Dein Zugang zu '.config('app.name'))
            ->greeting('Willkommen bei '.config('app.name'))
            ->line('Für dich wurde ein Zugang angelegt. Setze jetzt dein Passwort:')
            ->action('Passwort setzen', $url)
            ->line('Der Link ist zeitlich begrenzt gültig.');
    }
}
```
Hinweis: `$notifiable` ist der `User` (`Notifiable` + `CanResetPassword` vorhanden). `getEmailForPasswordReset()` liefert die E-Mail.

- [ ] **Step 4: `StoreUserRequest`** — `password` von `required` auf `['nullable', 'string', 'min:8']` ändern (Rest inkl. der admin/maintainer-nur-in-Operator-Org-Regel unverändert).

- [ ] **Step 5: `UserController`**
- `store`: User anlegen; wenn `password` vorhanden → wie bisher setzen (Cast hasht), keine Einladung. Wenn kein `password` → mit `Str::random(40)` als Passwort anlegen und `$user->notify(new UserInvitation)`. `email_verified_at` weiterhin per `forceFill(now())` (operator-angelegt, vertrauenswürdig). Erfolgsmeldung passend („… angelegt" bzw. „… eingeladen").
- Neue `invite(User $user)`-Action: `$user->notify(new UserInvitation); return back()->with('success', 'Einladung erneut gesendet.');`

- [ ] **Step 6:** Route in der `role:admin`-Gruppe:
```php
Route::post('users/{user}/invite', [Admin\UserController::class, 'invite'])->name('users.invite');
```

- [ ] **Step 7:** Tests grün (auch bestehende UserAdminTest — die legt Nutzer MIT Passwort an → weiterhin grün); Pint + PHPStan.
- [ ] **Step 8:** Commit `feat: invite users by email with a set-password link`.

---

### Task IV2: GUI — Einladen/Passwort-Umschalter + erneut senden

**Files:** `resources/js/pages/admin/users/Index.vue`.

- [ ] **Step 1:** Im Anlege-Formular einen Umschalter (Radio/Toggle) „Einladung per E-Mail senden" (Default) ↔ „Passwort direkt setzen". Bei „Einladung" wird das Passwort-Feld ausgeblendet und NICHT mitgesendet (→ Backend verschickt Einladung); bei „Passwort setzen" erscheint das Passwort-Feld (Pflicht im UI). Nutze `useForm`; sende `password` nur im Passwort-Modus.
- [ ] **Step 2:** In der Nutzer-Tabelle pro Zeile eine Aktion „Einladung senden" → `router.post(route('admin.users.invite', user.id), {}, { preserveScroll: true })`.
- [ ] **Step 3:** `ddev exec npm run build` (ohne Fehler) + `ddev exec npm run lint:check` (sauber). Bestehende Tests unberührt.
- [ ] **Step 4:** Commit `feat: user invite/set-password toggle and resend action in the gui`.

---

### Task IV3: E2E-Einladungsflow + volle Suite

**Files:** `tests/Feature/Admin/UserInvitationFlowTest.php`.

- [ ] **Step 1: E2E-Test** — Betreiber lädt einen Nutzer ein (ohne Passwort); über den Broker-Token wird via `POST /reset-password` ein Passwort gesetzt; danach kann sich der Nutzer einloggen.
```php
<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Password;

it('onboards an invited user who sets their own password and logs in', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($operator)->create(['role' => UserRole::Admin]);
    $cust = Organization::factory()->create();

    // Einladen (ohne Passwort)
    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'Kunde', 'email' => 'invite@kunde.test', 'organization_id' => $cust->id, 'role' => 'member',
    ])->assertRedirect();
    $user = User::where('email', 'invite@kunde.test')->firstOrFail();

    // Token wie in der Einladung erzeugen und Passwort setzen (nutzt NewPasswordController)
    $token = Password::broker()->createToken($user);
    $this->post('/logout');
    $this->post('/reset-password', [
        'token' => $token, 'email' => 'invite@kunde.test',
        'password' => 'neues-geheim-1234', 'password_confirmation' => 'neues-geheim-1234',
    ])->assertSessionHasNoErrors();

    // Einloggen mit dem selbst gesetzten Passwort
    $this->post('/logout');
    $this->post('/login', ['email' => 'invite@kunde.test', 'password' => 'neues-geheim-1234'])
        ->assertRedirect();
    $this->assertAuthenticatedAs($user->fresh());
});
```
Falls der Reset-Flow ein anderes Feld/andere Route erwartet, prüfe `routes/auth.php` (`password.store` = POST `/reset-password`) und `NewPasswordController` und passe NUR die Feldnamen an; die Aussage (eingeladener Nutzer setzt Passwort und loggt sich ein) muss erhalten bleiben.

- [ ] **Step 2:** Volle Suite `ddev exec vendor/bin/pest` → alle grün (Gesamtzahl melden). `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run build`, `ddev exec npm run lint:check`.
- [ ] **Step 3:** Commit `test: end-to-end user invitation and self-set password`.

---

## Self-Review

- **Deckt den Follow-up:** Einladung per E-Mail statt Klartext-Passwort; Betreiber gibt kein fremdes Passwort mehr vor; erneut senden möglich.
- **Sicherheit:** Set-Passwort-Link über den Standard-Broker (zeitlich begrenzt, einmalig); Einladen/Anlegen operator-`role:admin`-gated; Passwort-Direktsetzen bleibt optional; keine Secrets in Props. Der Rollen-Invariant (admin/maintainer nur Operator-Org) aus v2.0 bleibt aktiv.
- **Verschoben/Follow-up:** eigenes Mail-Template/Branding statt Default-MailMessage; Ablauf/Widerruf von Einladungen sichtbar machen; OCI (Phase 2).

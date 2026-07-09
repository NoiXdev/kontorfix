# Kontorfix v3.0a – Horizon (Queue-Dashboard) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Laravel Horizon auf der vorhandenen Redis-Queue — ein Dashboard unter `/horizon` (nur für Betreiber-Admins), Supervisor-Config, fehlgeschlagene Jobs mit Retry per GUI; der Worker-Container läuft `horizon` statt `queue:work`.

**Architecture:** Queue ist bereits Redis (`SyncPackage`/`DeliverWebhook` sind `ShouldQueue`). Horizon ersetzt den nackten Worker durch supervidierte Worker + ein Web-Dashboard. Zugang wird über den `Horizon::auth()`-Gate `viewHorizon` auf Operator-Admins (`role === admin && organization.is_operator`) beschränkt — analog zur v2.0-Operator-Invariante. Deployment: `docker/entrypoint.sh` startet für `CONTAINER_ROLE=worker` künftig `php artisan horizon`.

**Tech Stack:** Laravel 12, `laravel/horizon`, Redis (phpredis), Pest, Pint, Larastan L6.

---

## File Structure

- Add `laravel/horizon` (composer); `horizon:install` erzeugt `config/horizon.php`, `app/Providers/HorizonServiceProvider.php`, published assets.
- Modify `bootstrap/providers.php` — HorizonServiceProvider registrieren.
- Modify `app/Providers/HorizonServiceProvider.php` — `viewHorizon`-Gate auf Operator-Admins.
- Modify `docker/entrypoint.sh` — worker-Rolle → `php artisan horizon`.
- Modify `resources/js/components/AppSidebar.vue` — Link „Queue / Horizon" (`/horizon`, admin-only, externer/Voll-Reload-Link).
- Modify `docs/oidc-setup.md`-Nachbar: kurze Betriebsnotiz (optional).
- Tests: `tests/Feature/HorizonAccessTest.php`.

---

### Task H0: Installation (inline vor H1 bereits erledigt/prüfen)

- [ ] **Step 1:** Prüfen, dass `laravel/horizon` installiert ist: `ddev exec php -r "require 'vendor/autoload.php'; echo class_exists(\Laravel\Horizon\Horizon::class) ? 'OK' : 'MISSING';"`. Falls MISSING: `ddev composer require laravel/horizon` + `ddev exec php artisan horizon:install`.
- [ ] **Step 2:** `HorizonServiceProvider::class` in `bootstrap/providers.php` eintragen (falls `horizon:install` das nicht schon tat).
- [ ] **Step 3:** Commit `build: add laravel/horizon` (falls in diesem Task installiert).

---

### Task H1: Zugangs-Gate (Operator-Admins) + Test

**Files:** `app/Providers/HorizonServiceProvider.php`, Test `tests/Feature/HorizonAccessTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows only operator admins to view horizon', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $operatorAdmin = User::factory()->for($operator)->create(['role' => UserRole::Admin]);
    $operatorMaintainer = User::factory()->for($operator)->create(['role' => UserRole::Maintainer]);
    $customerAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);

    expect(Gate::forUser($operatorAdmin)->allows('viewHorizon'))->toBeTrue();
    expect(Gate::forUser($operatorMaintainer)->allows('viewHorizon'))->toBeFalse();
    expect(Gate::forUser($customerAdmin)->allows('viewHorizon'))->toBeFalse();
});
```

- [ ] **Step 2:** Run → FAIL (Default-Gate erlaubt niemanden bzw. andere Logik).

- [ ] **Step 3:** In `app/Providers/HorizonServiceProvider::gate()` (bzw. `boot`) den Gate setzen:
```php
protected function gate(): void
{
    Gate::define('viewHorizon', function ($user) {
        return $user->role === \App\Enums\UserRole::Admin
            && (bool) $user->organization?->is_operator;
    });
}
```
(Imports/Struktur wie vom `horizon:install`-Stub vorgegeben; nur die Callback-Logik ersetzen.)

- [ ] **Step 4:** Run → PASS; Pint + PHPStan.
- [ ] **Step 5:** Commit `feat: restrict horizon dashboard to operator admins`.

---

### Task H2: Deployment (Worker → Horizon) + Nav-Link + Betriebsnotiz

**Files:** `docker/entrypoint.sh`, `resources/js/components/AppSidebar.vue`, `docs/horizon-ops.md` (neu), Test `tests/Feature/HorizonAccessTest.php` (Ergänzung optional).

- [ ] **Step 1:** `docker/entrypoint.sh` — den `worker`-Zweig auf Horizon umstellen:
```sh
elif [ "$role" = "worker" ]; then
    exec php artisan horizon
```
(Der `scheduler`-Zweig bleibt `schedule:work`.) Hinweis: Horizon braucht `ext-pcntl`/`ext-posix` im Image — prüfe/notiere das in `docs/horizon-ops.md` (FrankenPHP-Base hat pcntl i.d.R.; falls nicht, im Dockerfile ergänzen — nur DOKUMENTIEREN, nicht raten).

- [ ] **Step 2:** `AppSidebar.vue` — in der admin-only „Verwaltung"-Sektion einen Eintrag „Queue" → `/horizon` ergänzen. **Wichtig:** `/horizon` ist eine Nicht-Inertia-Route (eigene Horizon-SPA) → als echter Browser-Link rendern (voller Reload), NICHT als Inertia-`Link` mit XHR. Da NavMain Inertia-`Link` nutzt, entweder einen separaten `<a href="/horizon">`-Eintrag außerhalb von NavMain (z.B. im Footer via NavFooter, der `<a target>` nutzt) einhängen, ODER NavMain um „externe" Items erweitern. Einfachste saubere Lösung: den Horizon-Link in `footerNavItems` aufnehmen (NavFooter rendert `<a href target=_blank>`), nur wenn `isAdmin`. Setze `target` NICHT auf `_blank` erzwungen, falls störend — ein normaler Link reicht; wenn NavFooter hart `_blank` nutzt, ist das für ein Dashboard ok.

- [ ] **Step 3:** `docs/horizon-ops.md` — kurze Betriebsdoku: Worker läuft jetzt `php artisan horizon`; Dashboard `/horizon` nur für Operator-Admins; failed jobs + Retry dort; `ext-pcntl`/`ext-posix`-Anforderung; `horizon:terminate` beim Deploy (graceful restart) als Hinweis.

- [ ] **Step 4:** `ddev exec npm run build` + `ddev exec npm run lint:check` sauber; **volle Suite** `ddev exec vendor/bin/pest` grün (Gesamtzahl melden); `ddev exec vendor/bin/pint --test`; `ddev exec vendor/bin/phpstan analyse`.
- [ ] **Step 5:** Commit `feat: run horizon in the worker container and link the dashboard`.

---

## Self-Review

- **Deckt den Wunsch:** Horizon-Dashboard mit Durchsatz/Supervisor/Failed-Job-Retry; Worker supervidiert.
- **Sicherheit:** `/horizon` nur für Operator-Admins (`viewHorizon`-Gate, per Test belegt) — kein Kunden-/Maintainer-Zugriff auf Queue-Interna. Horizon nutzt dieselbe Redis-Queue; keine Datenmodell-Änderung.
- **Deployment:** entrypoint worker → `horizon`; `horizon:terminate` beim Rolling-Deploy dokumentiert; pcntl/posix-Anforderung notiert.
- **Verschoben/Follow-up:** v3.0b Scheduler (periodischer Re-Sync + Cleanup), v3.0c Reverb (Live-Updates).

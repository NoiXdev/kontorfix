# Kontorfix v3.0c – Reverb (Live-Updates) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Live-Updates per WebSocket (Reverb + Echo): `PackageSynced`/`PackageSyncFailed` werden auf einen privaten Operator-Channel gebroadcastet; das Dashboard und die Paketlisten/-detailseiten aktualisieren den Sync-Status live und zeigen Toasts.

**Architecture:** Reverb-Scaffolding ist da (config/reverb.php, config/broadcasting.php, routes/channels.php, `bootstrap/app.php` mit `channels:`). Die bestehenden Events werden `ShouldBroadcast` und senden auf `PrivateChannel('operator')` — Channel-Auth erlaubt NUR Operator-Org-Nutzer (`is_operator`), sodass Kunden keine fremden Events sehen. Frontend: Laravel Echo (Reverb-Client) abonniert den Operator-Channel; Dashboard/Paketansichten reagieren live. Deployment: ein `reverb`-Container (`php artisan reverb:start`).

**Tech Stack:** Laravel 12, `laravel/reverb`, `laravel-echo` + `pusher-js`, Redis, Pest, Pint, Larastan L6.

---

## File Structure

- Modify `app/Events/PackageSynced.php`, `app/Events/PackageSyncFailed.php` — `ShouldBroadcast`.
- Modify `routes/channels.php` — `operator`-Channel.
- Modify `.env`, `.env.example`, `phpunit.xml` — BROADCAST/REVERB-Env (Tests: `null`).
- Create `resources/js/echo.ts`; modify `resources/js/app.ts` — Echo booten.
- Modify `resources/js/pages/Dashboard.vue`, `admin/packages/Index.vue`, `admin/packages/Show.vue` — Live-Subscription + Toasts.
- Modify `docker/compose.yaml`, `docker/entrypoint.sh` — reverb-Service; Create `docs/reverb-ops.md`.
- Tests: `tests/Feature/Broadcast/OperatorChannelTest.php`, `tests/Unit/PackageBroadcastTest.php`.

---

### Task RV1: Broadcast-Backend + Operator-Channel-Auth

**Files:** `app/Events/PackageSynced.php`, `app/Events/PackageSyncFailed.php`, `routes/channels.php`, `.env`, `.env.example`, `phpunit.xml`, Tests.

- [ ] **Step 1: Failing tests**

`tests/Feature/Broadcast/OperatorChannelTest.php` (Channel-Auth — sicherheitskritisch):
```php
<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

it('authorizes only operator-org users on the private operator channel', function () {
    $operatorUser = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
    $maintainer = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Maintainer]);
    $customer = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Member]);

    // Broadcasting-Auth-Endpoint (via withRouting channels registriert)
    $this->actingAs($operatorUser)->post('/broadcasting/auth', ['channel_name' => 'private-operator', 'socket_id' => '123.456'])->assertOk();
    $this->actingAs($maintainer)->post('/broadcasting/auth', ['channel_name' => 'private-operator', 'socket_id' => '123.456'])->assertOk();
    $this->actingAs($customer)->post('/broadcasting/auth', ['channel_name' => 'private-operator', 'socket_id' => '123.456'])->assertForbidden();
});
```
(Operator-Org-Nutzer — Admin UND Maintainer — dürfen; Kunden-Org-Nutzer nicht. Falls die Auth-Antwort für „erlaubt" 200 mit JSON und für „verboten" 403 ist, passt der Test; falls das Format abweicht, prüfe die tatsächliche Reverb/Broadcasting-Auth-Antwort und passe die Status-Assertions an — die Aussage „nur Operator-Org erlaubt" muss erhalten bleiben.)

`tests/Unit/PackageBroadcastTest.php` (Event-Form):
```php
<?php

use App\Events\PackageSynced;
use App\Models\Package;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('broadcasts package sync on the private operator channel with the package payload', function () {
    $pkg = Package::factory()->create(['name' => 'acme/widget']);
    $event = new PackageSynced($pkg);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    expect($event->broadcastOn())->toBeInstanceOf(PrivateChannel::class);
    expect($event->broadcastOn()->name)->toBe('private-operator');
    expect($event->broadcastWith())->toMatchArray(['name' => 'acme/widget']);
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: Events broadcastbar machen** — `PackageSynced`:
```php
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class PackageSynced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Package $package) {}

    /** @return PrivateChannel */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('operator');
    }

    public function broadcastAs(): string
    {
        return 'package.synced';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->package->id,
            'name' => $this->package->name,
            'type' => $this->package->type->value,
            'sync_status' => $this->package->sync_status->value,
        ];
    }
}
```
`PackageSyncFailed` analog (broadcastAs `package.sync_failed`, `broadcastWith` zusätzlich `'error' => $this->error`, sync_status kommt aus dem Modell).
**Wichtig:** Der bestehende `DispatchOutgoingWebhooks`-Listener bleibt unverändert (Events werden weiterhin normal dispatcht + jetzt zusätzlich gebroadcastet).

- [ ] **Step 4: `routes/channels.php`** — Operator-Channel ergänzen (die Default-`App.Models.User.{id}`-Zeile darf bleiben):
```php
Broadcast::channel('operator', function ($user) {
    return (bool) $user->organization?->is_operator;
});
```

- [ ] **Step 5: Env** — in `.env` UND `.env.example`: `BROADCAST_CONNECTION=reverb` sowie `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST=localhost`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`, plus die Vite-Spiegel `VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"`, `VITE_REVERB_HOST="${REVERB_HOST}"`, `VITE_REVERB_PORT="${REVERB_PORT}"`, `VITE_REVERB_SCHEME="${REVERB_SCHEME}"` (in `.env.example` mit Platzhalterwerten). In `phpunit.xml` `<env name="BROADCAST_CONNECTION" value="null"/>` setzen, damit Tests nicht wirklich broadcasten.

- [ ] **Step 6:** Alle Tests grün; **volle Suite** (die ShouldBroadcast-Änderung darf bestehende Sync-/Webhook-Tests nicht brechen — mit BROADCAST=null no-op); Pint + PHPStan.
- [ ] **Step 7:** Commit `feat: broadcast package sync events on a private operator channel`.

---

### Task RV2: Frontend — Echo + Live-Updates

**Files:** `package.json` (laravel-echo, pusher-js), `resources/js/echo.ts`, `resources/js/app.ts`, `resources/js/pages/Dashboard.vue`, `resources/js/pages/admin/packages/Index.vue`, `resources/js/pages/admin/packages/Show.vue`.

- [ ] **Step 1:** `ddev exec npm install --save-dev laravel-echo pusher-js`.
- [ ] **Step 2:** `resources/js/echo.ts` — Echo mit Reverb konfigurieren:
```ts
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```
(TS-Typen für `window.Echo`/`window.Pusher` via `declare global` ergänzen, damit ESLint/tsc sauber bleiben.) In `resources/js/app.ts` `import './echo';` ergänzen.
- [ ] **Step 3:** Ein kleines Composable/Helper (z.B. `resources/js/composables/useOperatorChannel.ts`) das `window.Echo.private('operator').listen('.package.synced', cb).listen('.package.sync_failed', cb)` kapselt und beim Unmount `leaveChannel` aufräumt.
- [ ] **Step 4:** In `Dashboard.vue`, `admin/packages/Index.vue`, `admin/packages/Show.vue`: den Operator-Channel abonnieren; bei `package.synced`/`package.sync_failed` den betroffenen Paket-Sync-Status im lokalen State live aktualisieren (Index/Show) bzw. auf dem Dashboard einen dezenten Hinweis/Toast zeigen. Nur mounten, wenn der Nutzer Operator ist (aus `page.props.auth.user`). Halte es robust: wenn Echo/WS nicht verbunden ist, darf nichts crashen (try/catch, optional chaining).
- [ ] **Step 5:** `ddev exec npm run build` + `ddev exec npm run lint:check` sauber. (Live-Verhalten ist nur mit laufendem Reverb testbar — hier reicht Build/Lint + manuelle Notiz.)
- [ ] **Step 6:** Commit `feat: live sync-status updates via laravel echo`.

---

### Task RV3: Deployment (Reverb-Container) + Doku + volle Suite

**Files:** `docker/compose.yaml`, `docker/entrypoint.sh`, `docs/reverb-ops.md`.

- [ ] **Step 1:** `docker/entrypoint.sh` — einen `reverb`-Zweig ergänzen:
```sh
elif [ "$role" = "reverb" ]; then
    exec php artisan reverb:start --host=0.0.0.0 --port=8080
```
- [ ] **Step 2:** `docker/compose.yaml` — einen `reverb`-Service (gleiches Image, `CONTAINER_ROLE: reverb`, Port z.B. `8081:8080`, `env_file: .env`, depends_on redis, restart unless-stopped). Hinweis: der öffentliche WS-Endpunkt läuft in Prod über den Reverse-Proxy (Traefik) mit TLS — in `docs/reverb-ops.md` dokumentieren (REVERB_HOST/SCHEME = öffentliche Domain/wss).
- [ ] **Step 3:** `docs/reverb-ops.md` — Betrieb: reverb-Container (`reverb:start`); REVERB_*/VITE_REVERB_* env; Proxy/TLS/`wss`; privater `operator`-Channel (nur Operator-Org); für lokale Dev `ddev exec php artisan reverb:start`.
- [ ] **Step 4:** **Volle Suite** `ddev exec vendor/bin/pest` grün (Gesamtzahl melden); `ddev exec vendor/bin/pint --test`; `ddev exec vendor/bin/phpstan analyse`; `ddev exec npm run build` + `lint:check`.
- [ ] **Step 5:** Commit `feat: reverb container and operations docs`.

---

## Self-Review

- **Deckt den Wunsch:** Live-Updates via Reverb/Echo; Sync-Status/Toasts in Echtzeit.
- **Sicherheit:** privater `operator`-Channel — Auth nur für Operator-Org-Nutzer (per Test belegt), Kunden sehen keine fremden Sync-Events; Broadcast-Payload enthält nur nicht-geheime Paketfelder. Tests broadcasten nicht (BROADCAST=null).
- **Robustheit:** Events zusätzlich gebroadcastet, bestehender Webhook-Fan-out unberührt; Frontend crasht nicht ohne WS-Verbindung.
- **Verschoben/Follow-up:** per-Org-Kundenkanäle fürs Portal (Live-Updates auch für Kunden) — aktuell nur Operator; Broadcast von Webhook-Delivery-Events; OCI (Phase 2).

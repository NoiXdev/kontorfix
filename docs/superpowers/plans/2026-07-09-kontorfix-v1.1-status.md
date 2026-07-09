# Kontorfix v1.1 – Statusseite (Health) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Betreiber sehen auf einer Statusseite die Betriebsgesundheit: Datenbank, Redis/Cache, Queue (+ Anzahl fehlgeschlagener Jobs), Storage-Erreichbarkeit und Upstream-Status — jeweils grün/rot mit Detail.

**Architecture:** Ein `HealthService` sammelt eine Liste normalisierter Checks (`{key,label,ok,detail}`). Jeder Check ist defensiv (try/catch → ok:false + Meldung), damit ein ausgefallener Teil die Seite nicht bricht. DB/Cache/Queue-Checks nutzen die Framework-Facades; Storage delegiert an den vorhandenen `StorageManager::testConnection()`; Upstream-Checks holen die konfigurierten `Upstream`-URLs mit kurzem Timeout, SSRF-gesichert über `UrlSafety::isSafeResolving()`, ohne Redirects. Ein Admin-Controller rendert die Checks in eine Inertia-Seite.

**Tech Stack:** Laravel 12 (DB/Cache/Queue-Facades, HTTP-Client), Inertia v2 + Vue 3, Pest (`Http::fake`), Pint, Larastan L6.

---

## File Structure

- Create `app/Services/Health/HealthService.php` — alle Checks.
- Create `app/Http/Controllers/Admin/StatusController.php`.
- Modify `routes/web.php` — `admin.status` in der `role:admin,maintainer`-Gruppe.
- Create `resources/js/pages/admin/status/Index.vue`.
- Tests: `tests/Unit/HealthServiceTest.php`, `tests/Feature/Admin/StatusPageTest.php`.

---

### Task St1: HealthService

**Files:** `app/Services/Health/HealthService.php`, Test `tests/Unit/HealthServiceTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Models\Upstream;
use App\Services\Health\HealthService;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports healthy core services and reachable upstreams', function () {
    Http::fake(['https://packagist.example/*' => Http::response('ok', 200)]);
    Upstream::factory()->create(['url' => 'https://packagist.example/packages.json']);

    $checks = collect(app(HealthService::class)->checks())->keyBy('key');

    expect($checks['database']['ok'])->toBeTrue();
    expect($checks['cache']['ok'])->toBeTrue();
    expect($checks->has('queue'))->toBeTrue();       // vorhanden (ok abhängig vom Treiber)
    expect($checks['storage']['ok'])->toBeTrue();

    $upstream = collect(app(HealthService::class)->checks())->firstWhere('key', 'upstream:'.Upstream::first()->id);
    expect($upstream['ok'])->toBeTrue();
});

it('marks an unreachable upstream as failed but does not throw', function () {
    Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused')]);
    Upstream::factory()->create(['url' => 'https://down.example/x']);

    $checks = collect(app(HealthService::class)->checks());
    $u = $checks->firstWhere('key', 'upstream:'.Upstream::first()->id);
    expect($u['ok'])->toBeFalse();
});

it('reports the failed jobs count in the queue check detail', function () {
    \DB::table('failed_jobs')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'redis', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
    ]);

    $queue = collect(app(HealthService::class)->checks())->firstWhere('key', 'queue');
    expect($queue['detail'])->toContain('1'); // 1 fehlgeschlagener Job
});
```
Prüfe die `Upstream`-Factory-Pflichtfelder (`type` etc.) und ergänze im Test bei Bedarf `'type' => 'composer'`.

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: Implement**
```php
<?php

namespace App\Services\Health;

use App\Models\Upstream;
use App\Services\Storage\StorageManager;
use App\Services\Upstream\UrlSafety;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

class HealthService
{
    public function __construct(private StorageManager $storage) {}

    /** @return list<array{key:string,label:string,ok:bool,detail:string}> */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->cache(),
            $this->queue(),
            $this->storageCheck(),
            ...$this->upstreams(),
        ];
    }

    /** @return array{key:string,label:string,ok:bool,detail:string} */
    private function database(): array
    {
        try {
            DB::select('select 1');

            return ['key' => 'database', 'label' => 'Datenbank', 'ok' => true, 'detail' => 'Verbunden.'];
        } catch (Throwable $e) {
            return ['key' => 'database', 'label' => 'Datenbank', 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** @return array{key:string,label:string,ok:bool,detail:string} */
    private function cache(): array
    {
        try {
            $probe = 'health:'.Str::random(8);
            Cache::put($probe, 'ok', 5);
            $ok = Cache::get($probe) === 'ok';
            Cache::forget($probe);

            return ['key' => 'cache', 'label' => 'Cache / Redis', 'ok' => $ok, 'detail' => $ok ? 'Schreib-/Leseprobe ok.' : 'Probe fehlgeschlagen.'];
        } catch (Throwable $e) {
            return ['key' => 'cache', 'label' => 'Cache / Redis', 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** @return array{key:string,label:string,ok:bool,detail:string} */
    private function queue(): array
    {
        $failed = 0;
        try {
            $failed = DB::table('failed_jobs')->count();
        } catch (Throwable) {
            // Tabelle fehlt evtl. — dann 0.
        }

        try {
            $size = Queue::size();
            $detail = "Wartend: {$size}, fehlgeschlagen: {$failed}.";

            return ['key' => 'queue', 'label' => 'Queue', 'ok' => $failed === 0, 'detail' => $detail];
        } catch (Throwable $e) {
            // Manche Treiber (sync) unterstützen size() nicht — trotzdem Failed-Count zeigen.
            return ['key' => 'queue', 'label' => 'Queue', 'ok' => $failed === 0, 'detail' => "Fehlgeschlagen: {$failed}. ({$e->getMessage()})"];
        }
    }

    /** @return array{key:string,label:string,ok:bool,detail:string} */
    private function storageCheck(): array
    {
        $result = $this->storage->testConnection();

        return ['key' => 'storage', 'label' => 'Storage', 'ok' => $result['ok'], 'detail' => $result['message']];
    }

    /** @return list<array{key:string,label:string,ok:bool,detail:string}> */
    private function upstreams(): array
    {
        return Upstream::query()->get()->map(function (Upstream $u): array {
            $label = 'Upstream: '.$u->url;

            if (! UrlSafety::isSafeResolving($u->url)) {
                return ['key' => 'upstream:'.$u->id, 'label' => $label, 'ok' => false, 'detail' => 'Unsichere/nicht auflösbare URL.'];
            }

            try {
                $response = Http::timeout(5)->withoutRedirecting()->get($u->url);

                return ['key' => 'upstream:'.$u->id, 'label' => $label, 'ok' => $response->status() < 500, 'detail' => 'HTTP '.$response->status()];
            } catch (Throwable $e) {
                return ['key' => 'upstream:'.$u->id, 'label' => $label, 'ok' => false, 'detail' => $e->getMessage()];
            }
        })->all();
    }
}
```
Hinweis: `Queue::size()` schlägt bei manchen Test-Treibern fehl → im try/catch abgefangen. `UrlSafety::isSafeResolving` löst `packagist.example`/`down.example` (nicht auflösbar) → passiert, der `Http::fake` fängt den Request ab. Falls PHPStan über Facade-Rückgabetypen meckert, minimal casten.

- [ ] **Step 4:** Run → PASS; Regression `ddev exec vendor/bin/pest`; Pint + PHPStan.
- [ ] **Step 5:** Commit `feat: health service for status page (db, cache, queue, storage, upstreams)`.

---

### Task St2: StatusController + Route + Vue

**Files:** `app/Http/Controllers/Admin/StatusController.php`, `routes/web.php`, `resources/js/pages/admin/status/Index.vue`, Test `tests/Feature/Admin/StatusPageTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

it('shows the status page to an operator with health checks', function () {
    $admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/admin/status')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/status/Index')
            ->has('checks')
            ->where('checks', fn ($checks) => collect($checks)->contains(fn ($c) => $c['key'] === 'database' && $c['ok'] === true)));
});

it('is not reachable for regular members', function () {
    $member = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);
    $this->actingAs($member)->get('/admin/status')->assertForbidden();
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3:** `StatusController@index`:
```php
public function index(HealthService $health): Response
{
    return Inertia::render('admin/status/Index', ['checks' => $health->checks()]);
}
```

- [ ] **Step 4:** Route in `routes/web.php` — in die `role:admin,maintainer`-Gruppe (operativer Bereich; nicht die reine `role:admin`-Gruppe):
```php
Route::get('status', [Admin\StatusController::class, 'index'])->name('status');
```

- [ ] **Step 5:** `resources/js/pages/admin/status/Index.vue` (Muster: `admin/upstreams/Index.vue`): eine Karten-/Listenansicht der Checks; pro Check ein grünes/rotes Statussymbol, `label` und `detail`. Prop `checks: Array<{key,label,ok,detail}>`. Ein „Aktualisieren"-Button (Inertia-`router.reload({ only: ['checks'] })`). Deutsche, neutrale Labels.

- [ ] **Step 6:** Run → PASS; `ddev exec npm run build`; Pint + PHPStan.
- [ ] **Step 7:** Commit `feat: admin status page with operational health checks`.

---

### Task St3: E2E + volle Suite

- [ ] **Step 1:** `tests/Feature/StatusEndToEndTest.php` — Operator ruft `/admin/status`, sieht database/cache/storage grün; ein per `Http::fake` erreichbarer Upstream erscheint grün, ein via `ConnectionException` gefakter rot. (Nutzt dieselben Fake-Muster wie St1.)
- [ ] **Step 2:** Volle Suite `ddev exec vendor/bin/pest` → alle grün (Gesamtzahl melden). `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run build`.
- [ ] **Step 3:** Commit `test: end-to-end status page health checks`.

---

## Self-Review

- **Spec §9 „Statusseite: Queue-Health, Storage-Erreichbarkeit, Upstream-Status":** Queue (St1, inkl. Failed-Count), Storage (delegiert an v1.0-Test), Upstreams (Erreichbarkeit) ✓; dazu DB + Cache/Redis als Bonus.
- **Robustheit:** jeder Check in try/catch → ein Ausfall bricht die Seite nicht; Upstream-Checks SSRF-gesichert + kurzer Timeout + keine Redirects.
- **Autorisierung:** `role:admin,maintainer` (operativer Bereich; keine Secrets auf der Seite — nur Upstream-URLs, Job-Zahlen, Health-Booleans).
- **Verschoben/Follow-up:** Upstream-Checks laufen sequentiell (bei sehr vielen Upstreams langsam — ggf. cachen/parallelisieren); Dead-Letter-Detailansicht (einzelne failed_jobs mit Retry-Button) bewusst später; echte Horizon-Integration erst mit Horizon (aktuell nicht installiert).

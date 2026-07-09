# Kontorfix v3.0b – Scheduler (periodischer Re-Sync + Cleanup) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Der (schon deployte) Scheduler-Container bekommt echte Aufgaben: **stündlicher Re-Sync** aller VCS-Pakete (damit Pakete auch ohne Webhook aktuell bleiben) und **Aufräum-Tasks** (alte failed_jobs, alte Webhook-Delivery-Logs, Horizon-Metriken).

**Architecture:** Ein Artisan-Command `packages:resync` dispatcht `SyncPackage` für alle Pakete mit `repository_url` (SyncPackage hat `WithoutOverlapping` je Paket → re-dispatch ist sicher). Die Zeitpläne werden in `routes/console.php` über die `Schedule`-Facade definiert (Laravel-12-Idiom). `WebhookDelivery` wird `Prunable` (alte Zustellprotokolle). Der Scheduler-Container läuft bereits `php artisan schedule:work`.

**Tech Stack:** Laravel 12 (Console Commands, Scheduler, Prunable), Redis-Queue, Horizon, Pest, Pint, Larastan L6.

---

## File Structure

- Create `app/Console/Commands/ResyncPackages.php` (`packages:resync`).
- Modify `routes/console.php` — `Schedule::command(...)`-Einträge.
- Modify `app/Models/WebhookDelivery.php` — `Prunable` + `prunable()`-Query.
- Tests: `tests/Feature/Console/ResyncPackagesTest.php`, `tests/Feature/Console/ScheduleTest.php`, `tests/Unit/WebhookDeliveryPruneTest.php`.

---

### Task SB1: `packages:resync`-Command + stündlicher Zeitplan

**Files:** `app/Console/Commands/ResyncPackages.php`, `routes/console.php`, Tests `tests/Feature/Console/ResyncPackagesTest.php` + `tests/Feature/Console/ScheduleTest.php`.

- [ ] **Step 1: Failing test** `tests/Feature/Console/ResyncPackagesTest.php`
```php
<?php

use App\Jobs\SyncPackage;
use App\Models\Package;
use Illuminate\Support\Facades\Queue;

it('dispatches a sync job only for packages that have a repository url', function () {
    Queue::fake();
    $withRepo = Package::factory()->create(['repository_url' => 'https://github.com/acme/widget.git']);
    Package::factory()->create(['repository_url' => null]); // wird NICHT resynct

    $this->artisan('packages:resync')->assertSuccessful();

    Queue::assertPushed(SyncPackage::class, 1);
    Queue::assertPushed(SyncPackage::class, fn (SyncPackage $job) => $job->package->is($withRepo));
});
```
Prüfe die `SyncPackage`-Konstruktor-Signatur (`public function __construct(public Package $package)`) — der Zugriff `$job->package` sollte passen; falls das Property anders heißt, im Test anpassen.

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: Command**
```php
<?php

namespace App\Console\Commands;

use App\Jobs\SyncPackage;
use App\Models\Package;
use Illuminate\Console\Command;

class ResyncPackages extends Command
{
    protected $signature = 'packages:resync';

    protected $description = 'Reihte einen Sync-Job für jedes VCS-basierte Paket ein.';

    public function handle(): int
    {
        $count = 0;
        Package::query()->whereNotNull('repository_url')->each(function (Package $package) use (&$count) {
            SyncPackage::dispatch($package);
            $count++;
        });

        $this->info("{$count} Paket(e) zum Re-Sync eingereiht.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Zeitplan** in `routes/console.php`:
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('packages:resync')->hourly()->withoutOverlapping();
```
(Die bestehende `inspire`-Command-Definition unverändert lassen.)

- [ ] **Step 5: Schedule-Wiring-Test** `tests/Feature/Console/ScheduleTest.php`
```php
<?php

it('registers the hourly package resync in the schedule', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('packages:resync')->assertSuccessful();
});
```
(Falls `expectsOutputToContain` in dieser Pest/Laravel-Version nicht existiert, nutze `Artisan::call('schedule:list'); expect(Artisan::output())->toContain('packages:resync');`.)

- [ ] **Step 6:** Alle Tests grün; Pint + PHPStan.
- [ ] **Step 7:** Commit `feat: hourly re-sync of vcs packages via the scheduler`.

---

### Task SB2: Cleanup-Tasks (Failed-Jobs, Delivery-Logs, Horizon-Snapshot)

**Files:** `app/Models/WebhookDelivery.php`, `routes/console.php`, Tests `tests/Unit/WebhookDeliveryPruneTest.php` + Ergänzung `ScheduleTest.php`.

- [ ] **Step 1: Failing test** `tests/Unit/WebhookDeliveryPruneTest.php`
```php
<?php

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prunes webhook deliveries older than 30 days', function () {
    $webhook = Webhook::factory()->create();
    $old = WebhookDelivery::factory()->for($webhook)->create(['created_at' => now()->subDays(40)]);
    $recent = WebhookDelivery::factory()->for($webhook)->create(['created_at' => now()->subDays(5)]);

    $this->artisan('model:prune', ['--model' => [WebhookDelivery::class]])->assertSuccessful();

    expect(WebhookDelivery::find($old->id))->toBeNull();
    expect(WebhookDelivery::find($recent->id))->not->toBeNull();
});
```
Prüfe die `WebhookDeliveryFactory` und die `Webhook`-Relation (`WebhookDelivery` gehört zu `Webhook` via `webhook()` — belongsTo; `->for($webhook)` sollte greifen). Falls `created_at` nicht direkt setzbar ist (guarded), nutze `->create()` und danach `forceFill(['created_at' => ...])->save()`.

- [ ] **Step 2:** Run → FAIL (WebhookDelivery ist noch nicht `Prunable`).

- [ ] **Step 3:** `WebhookDelivery` `Prunable` machen:
```php
use Illuminate\Database\Eloquent\Prunable;
// ...
use Prunable;

/** @return \Illuminate\Database\Eloquent\Builder<WebhookDelivery> */
public function prunable(): \Illuminate\Database\Eloquent\Builder
{
    return static::where('created_at', '<=', now()->subDays(30));
}
```
(Trait + Methode ergänzen; bestehende Relationen/Casts unverändert.)

- [ ] **Step 4: Zeitpläne** in `routes/console.php` ergänzen:
```php
Schedule::command('model:prune', ['--model' => [\App\Models\WebhookDelivery::class]])->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();       // failed jobs > 7 Tage
Schedule::command('horizon:snapshot')->everyFiveMinutes();          // Horizon-Metriken
```

- [ ] **Step 5:** `ScheduleTest.php` um Assertions für `model:prune`/`queue:prune-failed`/`horizon:snapshot` ergänzen (oder einen zweiten `it`).

- [ ] **Step 6:** Alle Tests grün; **volle Suite** `ddev exec vendor/bin/pest` (Gesamtzahl melden); Pint + PHPStan.
- [ ] **Step 7:** Commit `feat: schedule cleanup of failed jobs, delivery logs and horizon snapshots`.

---

### Task SB3: Verifikation

- [ ] **Step 1:** Volle Suite grün, `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run build` (unverändert, aber prüfen). Falls schon in SB2 abgedeckt und nichts offen: überspringen.

---

## Self-Review

- **Deckt den Wunsch:** wiederkehrende Aufgaben laufen jetzt echt — stündlicher Re-Sync (Pakete aktuell ohne Webhook) + Cleanup; verdrahtet im vorhandenen Scheduler-Container.
- **Robustheit:** `packages:resync` nur für VCS-Pakete; SyncPackage `WithoutOverlapping` verhindert Doppel-Syncs; `withoutOverlapping` auf dem Command; Prunable begrenzt das Delivery-Log; failed_jobs werden beschnitten; Horizon-Snapshots für Metriken.
- **Sicherheit:** keine neuen HTTP-Endpunkte; alles Console/Scheduler.
- **Verschoben:** v3.0c Reverb (Live-Updates); OCI (Phase 2). Optional: Re-Sync-Intervall per Config statt fix stündlich.

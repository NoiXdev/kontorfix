# Kontorfix v1.0 – Konfigurierbarer Storage (Local / S3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Betreiber wählen per GUI das Storage-Backend für Artefakte/Dists/Mirror-Cache (lokal oder S3/MinIO/S3-kompatibel), tragen Zugangsdaten ein (verschlüsselt) und testen die Verbindung — ohne Redeploy oder .env-Änderung.

**Architecture:** Alle Storage-Zugriffe laufen bereits über die eine Flysystem-Disk `artifacts` (`Storage::disk('artifacts')`). Eine Singleton-Konfiguration (`StorageSetting` in der DB) hält Treiber + S3-Parameter (Secret verschlüsselt). Ein `StorageManager`-Service baut daraus das Flysystem-Config-Array; ein ServiceProvider überschreibt zur Laufzeit `config('filesystems.disks.artifacts')` aus der DB (Fallback: bestehende lokale Disk). Damit nutzen alle vorhandenen `artifacts`-Aufrufe transparent das konfigurierte Backend. Admin-GUI zum Bearbeiten + „Verbindung testen".

**Tech Stack:** Laravel 12 (Flysystem, `league/flysystem-aws-s3-v3` — muss ggf. installiert werden), Inertia v2 + Vue 3, Pest, Pint, Larastan L6.

---

## File Structure

- Create migration `create_storage_settings_table` + `app/Models/StorageSetting.php` (Singleton-Zugriff).
- Create `app/Services/Storage/StorageManager.php` — `diskConfig(): array`, `testConnection(): array{ok:bool,message:string}`, `current(): StorageSetting`.
- Create `app/Providers/StorageServiceProvider.php` (oder in `AppServiceProvider::boot`) — überschreibt `config('filesystems.disks.artifacts')` aus der DB.
- Create `app/Http/Controllers/Admin/StorageController.php` + `app/Http/Requests/Admin/UpdateStorageSettingRequest.php`.
- Modify `routes/web.php` (admin storage-Routen, `role:admin`).
- Create `resources/js/pages/admin/storage/Index.vue`.
- Tests: `tests/Unit/StorageManagerTest.php`, `tests/Feature/Admin/StorageSettingTest.php`, `tests/Feature/StorageBackendResolutionTest.php`.

---

### Task S0: S3-Flysystem-Adapter sicherstellen

Der `s3`-Treiber braucht `league/flysystem-aws-s3-v3`. Prüfen und ggf. installieren.

- [ ] **Step 1:** `ddev exec php -r "require 'vendor/autoload.php'; echo class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class) ? 'OK' : 'MISSING';"`
- [ ] **Step 2:** Falls `MISSING`: `ddev composer require league/flysystem-aws-s3-v3 "^3.0"`; erneut prüfen → `OK`. Falls bereits `OK`: diesen Task ohne Commit überspringen und im Bericht vermerken.
- [ ] **Step 3 (nur falls installiert):** Commit `build: add flysystem s3 adapter for configurable storage`.

---

### Task S1: StorageSetting + StorageManager + Runtime-Registrierung

**Files:** Migration, `app/Models/StorageSetting.php`, `app/Services/Storage/StorageManager.php`, `app/Providers/StorageServiceProvider.php` (in `bootstrap/providers.php` registrieren) ODER `AppServiceProvider::boot`, Tests `tests/Unit/StorageManagerTest.php` + `tests/Feature/StorageBackendResolutionTest.php`.

- [ ] **Step 1: Failing test** `tests/Unit/StorageManagerTest.php`
```php
<?php

use App\Models\StorageSetting;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a local disk config by default', function () {
    $config = app(StorageManager::class)->diskConfig();
    expect($config['driver'])->toBe('local');
    expect($config['root'])->toContain('artifacts');
});

it('builds an s3 disk config from the stored setting', function () {
    StorageSetting::current()->update([
        'driver' => 's3', 'key' => 'AKIA', 'secret' => 'shh', 'region' => 'eu-central-1',
        'bucket' => 'kontorfix', 'endpoint' => 'https://minio.test', 'use_path_style' => true,
    ]);

    $config = app(StorageManager::class)->diskConfig();
    expect($config['driver'])->toBe('s3')
        ->and($config['key'])->toBe('AKIA')
        ->and($config['secret'])->toBe('shh')
        ->and($config['bucket'])->toBe('kontorfix')
        ->and($config['endpoint'])->toBe('https://minio.test')
        ->and($config['use_path_style_endpoint'])->toBeTrue();

    // Secret ist verschlüsselt at rest.
    $raw = \DB::table('storage_settings')->value('secret');
    expect($raw)->not->toBe('shh');
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3: Migration** `create_storage_settings_table`:
```php
Schema::create('storage_settings', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('driver')->default('local');   // local | s3
    $table->string('key')->nullable();
    $table->text('secret')->nullable();            // encrypted
    $table->string('region')->nullable();
    $table->string('bucket')->nullable();
    $table->string('endpoint')->nullable();        // für MinIO/S3-kompatibel
    $table->string('url')->nullable();
    $table->boolean('use_path_style')->default(false);
    $table->timestamps();
});
```

- [ ] **Step 4: `StorageSetting`-Model** (`HasUuids`) mit casts `['secret' => 'encrypted', 'use_path_style' => 'bool']`, `$hidden = ['secret']`, `$fillable` aller Felder, und statischem Singleton-Zugriff:
```php
public static function current(): self
{
    return static::query()->firstOr(fn () => static::query()->create(['driver' => 'local']));
}
```

- [ ] **Step 5: `StorageManager`**
```php
<?php

namespace App\Services\Storage;

use App\Models\StorageSetting;

class StorageManager
{
    public function current(): StorageSetting
    {
        return StorageSetting::current();
    }

    /** @return array<string,mixed> */
    public function diskConfig(): array
    {
        $s = $this->current();

        if ($s->driver === 's3') {
            return [
                'driver' => 's3',
                'key' => $s->key,
                'secret' => $s->secret,
                'region' => $s->region,
                'bucket' => $s->bucket,
                'endpoint' => $s->endpoint ?: null,
                'url' => $s->url ?: null,
                'use_path_style_endpoint' => $s->use_path_style,
                'throw' => true,
            ];
        }

        return [
            'driver' => 'local',
            'root' => storage_path('app/artifacts'),
            'throw' => true,
        ];
    }

    /** @return array{ok:bool,message:string} */
    public function testConnection(): array
    {
        try {
            config(['filesystems.disks.artifacts' => $this->diskConfig()]);
            \Illuminate\Support\Facades\Storage::forgetDisk('artifacts');
            $disk = \Illuminate\Support\Facades\Storage::disk('artifacts');
            $probe = '.kontorfix-storage-check-'.bin2hex(random_bytes(4));
            $disk->put($probe, 'ok');
            $ok = $disk->get($probe) === 'ok';
            $disk->delete($probe);

            return ['ok' => $ok, 'message' => $ok ? 'Verbindung erfolgreich.' : 'Schreib-/Leseprobe fehlgeschlagen.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
```
Hinweis: `random_bytes` ist erlaubt (nicht `Str::random` mit gecachtem Zustand). Falls PHPStan die FQN-Facades bemängelt, ziehe sie in `use`-Imports.

- [ ] **Step 6: Runtime-Registrierung** — `app/Providers/StorageServiceProvider.php`:
```php
<?php

namespace App\Providers;

use App\Services\Storage\StorageManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class StorageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Nur wenn die Tabelle existiert (nicht während frischer Migrationen/Installs).
        try {
            if (! Schema::hasTable('storage_settings')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        config(['filesystems.disks.artifacts' => app(StorageManager::class)->diskConfig()]);
    }
}
```
Registriere den Provider in `bootstrap/providers.php`.

Feature-Test `tests/Feature/StorageBackendResolutionTest.php` — beweist, dass die `artifacts`-Disk der DB-Konfiguration folgt:
```php
<?php

use App\Models\StorageSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the artifacts disk from the stored local setting and can round-trip a file', function () {
    StorageSetting::current(); // local default
    // Provider hat beim Boot die Config gesetzt; Disk neu auflösen zur Sicherheit.
    Storage::forgetDisk('artifacts');
    Storage::disk('artifacts')->put('probe.txt', 'hello');
    expect(Storage::disk('artifacts')->get('probe.txt'))->toBe('hello');
    Storage::disk('artifacts')->delete('probe.txt');
});
```

- [ ] **Step 7:** `ddev exec php artisan migrate`; beide Tests + Regression `ddev exec vendor/bin/pest` grün; Pint + PHPStan.
- [ ] **Step 8:** Commit `feat: db-backed configurable artifacts storage (local/s3)`.

---

### Task S2: Admin-GUI (Storage-Settings + Verbindungstest)

**Files:** `app/Http/Controllers/Admin/StorageController.php`, `app/Http/Requests/Admin/UpdateStorageSettingRequest.php`, `routes/web.php`, `resources/js/pages/admin/storage/Index.vue`, Test `tests/Feature/Admin/StorageSettingTest.php`.

- [ ] **Step 1: Failing test**
```php
<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\StorageSetting;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
});

it('shows the current storage settings without exposing the secret', function () {
    StorageSetting::current()->update(['driver' => 's3', 'secret' => 'shh', 'bucket' => 'b']);
    $this->actingAs($this->admin)->get('/admin/storage')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/storage/Index')
            ->where('settings.driver', 's3')->where('settings.has_secret', true)->missing('settings.secret'));
});

it('forbids maintainers', function () {
    $m = User::factory()->for(Organization::factory())->create(['role' => UserRole::Maintainer]);
    $this->actingAs($m)->get('/admin/storage')->assertForbidden();
});

it('updates to s3 and keeps the existing secret when left blank', function () {
    StorageSetting::current()->update(['driver' => 's3', 'secret' => 'keep-me']);

    $this->actingAs($this->admin)->put('/admin/storage', [
        'driver' => 's3', 'key' => 'AKIA', 'region' => 'eu', 'bucket' => 'kontorfix',
        'endpoint' => 'https://minio.test', 'use_path_style' => true, // secret NICHT mitgeschickt
    ])->assertRedirect();

    $s = StorageSetting::current();
    expect($s->bucket)->toBe('kontorfix')->and($s->secret)->toBe('keep-me'); // altes Secret bleibt
});

it('runs a connection test for the local driver', function () {
    $this->actingAs($this->admin)->postJson('/admin/storage/test', ['driver' => 'local'])
        ->assertOk()->assertJsonPath('ok', true);
});
```

- [ ] **Step 2:** Run → FAIL.

- [ ] **Step 3:** `UpdateStorageSettingRequest` — `driver` required in:local,s3; s3-Felder `required_if:driver,s3` wo sinnvoll (key/region/bucket), `secret` nullable (leer = behalten), `endpoint`/`url` nullable url, `use_path_style` boolean.

- [ ] **Step 4:** `StorageController`:
- `show`: rendert `admin/storage/Index` mit `settings` (driver, key, region, bucket, endpoint, url, use_path_style, `has_secret` => `filled($s->secret)` — **nie** secret).
- `update(UpdateStorageSettingRequest)`: füllt die Settings; wenn `secret` leer/fehlt → bestehendes behalten (nicht überschreiben); `back()->with('success', ...)`.
- `test(Request)`: nimmt die eingereichten Werte NICHT zwingend — testet die AKTUELL gespeicherte Config über `app(StorageManager::class)->testConnection()` und gibt `response()->json($result)`. (Einfachste sichere Variante: erst speichern, dann testen; der Test oben schickt `driver:local` und erwartet ok — teste die gespeicherte/aktuelle Config, die per Default local ist.)

- [ ] **Step 5:** Routen in `routes/web.php` in der `role:admin`-Gruppe (neben oidc):
```php
Route::get('storage', [Admin\StorageController::class, 'show'])->name('storage.show');
Route::put('storage', [Admin\StorageController::class, 'update'])->name('storage.update');
Route::post('storage/test', [Admin\StorageController::class, 'test'])->name('storage.test');
```

- [ ] **Step 6:** `resources/js/pages/admin/storage/Index.vue` (Muster: `admin/oidc/Index.vue`): Treiber-Auswahl (local/s3); bei s3 die Felder key/secret(Passwort)/region/bucket/endpoint/url/use_path_style; „Speichern" (PUT) + „Verbindung testen" (POST JSON, zeigt ok/message). Secret nur schreibend, `has_secret`-Badge. Deutsche Labels, neutral.

- [ ] **Step 7:** `ddev exec vendor/bin/pest tests/Feature/Admin/StorageSettingTest.php` grün; `ddev exec npm run build`; Pint + PHPStan.
- [ ] **Step 8:** Commit `feat: admin gui for storage backend with connection test`.

---

### Task S3: E2E + volle Suite

- [ ] **Step 1:** `tests/Feature/StorageEndToEndTest.php` — Admin setzt (lokale) Storage-Settings über die GUI, danach schreibt ein realer Registry-Pfad (z.B. via `Storage::disk('artifacts')`) erfolgreich; Verbindungstest ok. (S3 wird nicht live getestet — nur die Config-Verdrahtung; für S3 reicht der Unit-Test der `diskConfig()`.)
- [ ] **Step 2:** Volle Suite `ddev exec vendor/bin/pest` → alle grün (Gesamtzahl melden). `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run build`.
- [ ] **Step 3:** Commit `test: end-to-end configurable storage backend`.

---

## Self-Review

- **Spec §7 „Storage-Backends (local, S3/Minio, weitere S3-kompatible) als per GUI konfigurierbare Disks":** DB-Config (S1), Runtime-Anwendung auf die eine `artifacts`-Disk (S1), Admin-GUI + Verbindungstest (S2) ✓. Artefakte/Dists/Mirror-Cache liegen bereits ausschließlich auf `artifacts` — keine weiteren Call-Sites nötig.
- **Sicherheit:** S3-Secret `encrypted` + `$hidden` + nie in Props; Storage-Config nur `role:admin`.
- **Robustheit:** Provider-Boot mit `Schema::hasTable`-Guard (kein Bruch bei frischer Migration/Install); Fallback lokale Disk.
- **Verschoben/Follow-up:** Statusseite (Queue/Storage/Upstream-Health) → v1.1 (der Storage-Verbindungstest ist ein Baustein davon); getrennte Disks pro Zweck (Cache vs. Dists) bewusst YAGNI; Bucket-Migration bestehender Artefakte beim Backend-Wechsel nicht automatisiert (Betreiber-Aufgabe, dokumentieren).

# Kontorfix v0.5 — Webhooks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** (1) Eingehende Webhooks: ein `git push` bei GitHub/GitLab/Gitea/Bitbucket löst automatisch die Re-Synchronisation der betroffenen Pakete aus. (2) Ausgehende Webhooks: konfigurierbare Endpoints erhalten bei Registry-Events (`package.synced`, `sync.failed`, `version.released`) eine HMAC-signierte HTTP-Zustellung mit Retry und Delivery-Log.

**Architecture:** Eingehend — ein öffentlicher, signatur-validierter Endpoint pro Provider (`POST /webhooks/{provider}`); der Payload liefert die Repository-URL, die (normalisiert) gegen `packages.repository_url` gematcht wird → `SyncPackage` je Treffer. HMAC/Token-Validierung mit einem Signing-Secret aus der Config. Ausgehend — Laravel-Events (`PackageSynced`, `PackageSyncFailed`) werden im `SyncPackage`-Job gefeuert; ein Listener stellt pro abonniertem `Webhook` einen `DeliverWebhook`-Queue-Job ein, der HMAC-signiert POSTet, bei Fehlern erneut versucht (`$tries`) und jeden Versuch in `webhook_deliveries` protokolliert. Alles über die bestehende Redis-Queue.

**Tech Stack:** wie v0.4. Laravel Events/Listeners, `Illuminate\Support\Facades\Http` (mit `Http::fake` in Tests). Kein neues Composer-Paket.

**Spec:** docs/superpowers/specs/2026-07-08-kontorfix-design.md §8 (Webhooks).

**Konventionen:** wie bisher — Conventional Commits + Footer `Co-Authored-By: Claude <noreply@anthropic.com>`; `ddev php artisan test --compact`; Pint/Larastan-level-6-clean (`ddev exec 'cd /var/www/html && vendor/bin/phpstan analyse --no-progress'`); GUI: `ddev npm run build`/`ddev npm run lint`; vor voller Suite `ddev exec 'rm -f /tmp/kfx-dist-* 2>/dev/null'`; **vor jedem Commit `git symbolic-ref --short HEAD` == `main`**; nicht pushen; `docs/`/`.claude/` nie anfassen; **NIE echtes Netz in Tests — immer `Http::fake`**; Larastan-Generics überall.

---

## Dateistruktur (neu/geändert)

```
config/kontorfix.php                         # incoming_webhook_secret
database/migrations/<ts>_create_webhooks_tables.php   # webhooks, webhook_deliveries
app/Enums/WebhookEvent.php                    # package.synced | sync.failed | version.released
app/Models/Webhook.php, WebhookDelivery.php
app/Services/Webhook/RepoUrlMatcher.php       # Repo-URL normalisieren + Pakete matchen
app/Services/Webhook/IncomingPayloadParser.php # provider-spezifische Payload -> repo-url + signature-check
app/Http/Controllers/Webhook/IncomingWebhookController.php
app/Events/PackageSynced.php, PackageSyncFailed.php
app/Listeners/DispatchOutgoingWebhooks.php
app/Jobs/DeliverWebhook.php
app/Http/Controllers/Admin/WebhookController.php + StoreWebhookRequest
app/Jobs/SyncPackage.php                      # Events feuern
routes/web.php (admin webhooks), routes/registry.php ODER neue routes für /webhooks
resources/js/pages/admin/webhooks/Index.vue
tests/Feature/Webhook/{IncomingWebhookTest,OutgoingWebhookTest,WebhookFlowTest}.php
tests/Feature/Admin/WebhookCrudTest.php
tests/Unit/RepoUrlMatcherTest.php
```

---

### Task W1: Schema + Modelle (Webhooks, Delivery-Log) + WebhookEvent-Enum

**Files:**
- Create: migration `create_webhooks_tables`, `app/Enums/WebhookEvent.php`, `app/Models/{Webhook,WebhookDelivery}.php`, Factories
- Modify: `config/kontorfix.php` (`incoming_webhook_secret`)
- Test: `tests/Feature/WebhookSchemaTest.php`

- [ ] **Step 1: Failing test**

```php
<?php // tests/Feature/WebhookSchemaTest.php
use App\Enums\WebhookEvent;
use App\Models\Organization;
use App\Models\Webhook;

it('stores an outgoing webhook with subscribed events and a delivery log', function () {
    $org = Organization::factory()->create();
    $wh = Webhook::factory()->for($org)->create([
        'url' => 'https://hooks.example.com/kfx',
        'secret' => 'shhh',
        'events' => [WebhookEvent::PackageSynced->value, WebhookEvent::SyncFailed->value],
    ]);

    $wh->deliveries()->create([
        'event' => WebhookEvent::PackageSynced->value,
        'payload' => ['package' => 'acme/demo'],
        'status_code' => 200,
        'success' => true,
        'attempts' => 1,
    ]);

    expect($wh->secret)->toBe('shhh')                       // encrypted at rest
        ->and($wh->events)->toContain('package.synced')
        ->and($wh->deliveries()->first()->success)->toBeTrue();
});
```

- [ ] **Step 2: FAIL**, **Step 3: Implement**

Enum:
```php
enum WebhookEvent: string
{
    case PackageSynced = 'package.synced';
    case SyncFailed = 'sync.failed';
    case VersionReleased = 'version.released';
}
```

Migration:
```php
Schema::create('webhooks', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('url');
    $table->text('secret')->nullable();     // encrypted; HMAC-Signing
    $table->jsonb('events');                 // list<WebhookEvent value>
    $table->boolean('enabled')->default(true);
    $table->timestamps();
});
Schema::create('webhook_deliveries', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('webhook_id')->constrained()->cascadeOnDelete();
    $table->string('event');
    $table->jsonb('payload');
    $table->unsignedSmallInteger('status_code')->nullable();
    $table->boolean('success')->default(false);
    $table->unsignedTinyInteger('attempts')->default(0);
    $table->text('error')->nullable();
    $table->timestamp('delivered_at')->nullable();
    $table->timestamps();
    $table->index(['webhook_id', 'created_at']);
});
```

Models (HasUuids, HasFactory, Larastan generics): `Webhook` (casts `secret => 'encrypted'`, `events => 'array'`, `enabled => 'bool'`; relations `organization(): BelongsTo`, `deliveries(): HasMany`; helper `subscribesTo(WebhookEvent): bool`). `WebhookDelivery` (casts `payload => array`, `success => bool`, `delivered_at => datetime`; `webhook(): BelongsTo`). Factories.

`config/kontorfix.php` — add:
```php
'incoming_webhook_secret' => env('KONTORFIX_INCOMING_WEBHOOK_SECRET'),
```

- [ ] **Step 4:** migrate, full suite green, pint, phpstan. **Step 5: Commit** — `feat: webhook and delivery-log schema`

---

### Task W2: RepoUrlMatcher + IncomingPayloadParser + Incoming-Endpoint

**Files:**
- Create: `app/Services/Webhook/RepoUrlMatcher.php`, `app/Services/Webhook/IncomingPayloadParser.php`, `app/Http/Controllers/Webhook/IncomingWebhookController.php`
- Modify: `routes/web.php` (public `/webhooks/{provider}` route, NO auth middleware, signature-gated)
- Test: `tests/Unit/RepoUrlMatcherTest.php`, `tests/Feature/Webhook/IncomingWebhookTest.php`

**RepoUrlMatcher:** normalisiert eine Git-URL auf `host/path` (ohne Schema, ohne `.git`, ohne `git@`-Prefix, lowercase host) und findet Pakete, deren `repository_url` normalisiert übereinstimmt.
```php
public function normalize(string $url): string
{
    $url = trim($url);
    $url = preg_replace('#^git@([^:]+):#', '$1/', $url);      // ssh scp-Form
    $url = preg_replace('#^[a-z0-9+]+://#i', '', $url);        // Schema
    $url = preg_replace('#^[^/@]*@#', '', $url);               // user@host
    $url = preg_replace('#\.git$#', '', $url);
    return strtolower(rtrim($url, '/'));
}
/** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Package> */
public function match(string $repoUrl): \Illuminate\Database\Eloquent\Collection
{
    $norm = $this->normalize($repoUrl);
    return \App\Models\Package::whereNotNull('repository_url')->get()
        ->filter(fn ($p) => $this->normalize($p->repository_url) === $norm)->values();
}
```
(Für v0.5 ist das In-Memory-Filter ok; ein normalisierter Index kann später folgen.)

**IncomingPayloadParser:** je Provider — Signatur prüfen + Repo-URL extrahieren.
- `github`: HMAC `X-Hub-Signature-256` (`sha256=` + hash_hmac('sha256', body, secret)); repo-url aus `repository.clone_url`.
- `gitlab`: Token-Header `X-Gitlab-Token` === secret; repo-url aus `repository.git_http_url` bzw. `project.git_http_url`.
- `gitea`: wie github (`X-Gitea-Signature` HMAC sha256, hex ohne Prefix); repo-url aus `repository.clone_url`.
- `bitbucket`: kein Standard-HMAC — Token als Query/Header vergleichen (`X-Hook-UUID` reicht nicht); für v0.5 einen `?token=`-Query gegen das Secret prüfen; repo-url aus `repository.links.html.href`.
Methoden: `verify(string $provider, Request $request, string $secret): bool`, `repoUrl(string $provider, array $payload): ?string`. Unbekannter Provider → abort 404 im Controller.

**IncomingWebhookController::handle(Request, string $provider):**
```php
$secret = (string) config('kontorfix.incoming_webhook_secret');
abort_if($secret === '', 503, 'Incoming webhooks are not configured.');
abort_unless($this->parser->verify($provider, $request, $secret), 401);

$repoUrl = $this->parser->repoUrl($provider, $request->json()->all());
abort_if($repoUrl === null, 422);

$packages = $this->matcher->match($repoUrl);
foreach ($packages as $package) {
    SyncPackage::dispatch($package);
}
return response()->json(['synced' => $packages->count()]);
```

- [ ] **Step 1: Failing tests**

```php
<?php // tests/Unit/RepoUrlMatcherTest.php
use App\Models\Package;
use App\Services\Webhook\RepoUrlMatcher;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('normalizes https, ssh and scp git urls to the same key', function () {
    $m = new RepoUrlMatcher;
    $key = 'github.com/acme/demo';
    expect($m->normalize('https://github.com/acme/demo.git'))->toBe($key)
        ->and($m->normalize('ssh://git@github.com/acme/demo.git'))->toBe($key)
        ->and($m->normalize('git@github.com:acme/demo.git'))->toBe($key)
        ->and($m->normalize('https://github.com/acme/demo/'))->toBe($key);
});

it('matches packages by normalized repository url', function () {
    $p = Package::factory()->create(['repository_url' => 'https://github.com/acme/demo.git']);
    Package::factory()->create(['repository_url' => 'https://github.com/other/thing.git']);
    $m = new RepoUrlMatcher;
    expect($m->match('git@github.com:acme/demo.git')->pluck('id')->all())->toBe([$p->id]);
});
```

```php
<?php // tests/Feature/Webhook/IncomingWebhookTest.php
use App\Jobs\SyncPackage;
use App\Models\Package;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => config(['kontorfix.incoming_webhook_secret' => 'topsecret']));

function githubPush(string $cloneUrl): array
{
    return ['repository' => ['clone_url' => $cloneUrl]];
}
function githubSig(array $payload): string
{
    return 'sha256='.hash_hmac('sha256', json_encode($payload), 'topsecret');
}

it('resyncs matching packages on a valid github push', function () {
    Queue::fake();
    $pkg = Package::factory()->create(['repository_url' => 'https://github.com/acme/demo.git']);
    $payload = githubPush('https://github.com/acme/demo.git');

    $this->withHeaders(['X-Hub-Signature-256' => githubSig($payload)])
        ->postJson('/webhooks/github', $payload)->assertOk()->assertJsonPath('synced', 1);

    Queue::assertPushed(SyncPackage::class, fn ($job) => $job->package->is($pkg));
});

it('rejects a github push with a bad signature', function () {
    Queue::fake();
    Package::factory()->create(['repository_url' => 'https://github.com/acme/demo.git']);
    $payload = githubPush('https://github.com/acme/demo.git');

    $this->withHeaders(['X-Hub-Signature-256' => 'sha256=deadbeef'])
        ->postJson('/webhooks/github', $payload)->assertUnauthorized();
    Queue::assertNothingPushed();
});

it('accepts a valid gitlab token push', function () {
    Queue::fake();
    $pkg = Package::factory()->create(['repository_url' => 'https://gitlab.com/acme/demo.git']);

    $this->withHeaders(['X-Gitlab-Token' => 'topsecret'])
        ->postJson('/webhooks/gitlab', ['repository' => ['git_http_url' => 'https://gitlab.com/acme/demo.git']])
        ->assertOk()->assertJsonPath('synced', 1);
    Queue::assertPushed(SyncPackage::class);
});

it('503 when no incoming secret is configured', function () {
    config(['kontorfix.incoming_webhook_secret' => null]);
    $this->postJson('/webhooks/github', githubPush('https://github.com/acme/demo.git'))->assertStatus(503);
});
```

**Route** (`routes/web.php`, OUTSIDE auth, its own group — kein CSRF: registriere in `bootstrap/app.php` `then:` neben registry ODER als API-Route ohne web-Middleware; einfachste Variante: `Route::post('/webhooks/{provider}', ...)` in einer eigenen `withoutMiddleware`-Gruppe oder in `routes/registry.php`-Stil geladen). Wichtig: KEINE Session/CSRF (externe Aufrufer).

- [ ] **Step 2: FAIL**, **Step 3: Implement**, **Step 4: grün + pint + phpstan.** **Step 5: Commit** — `feat: incoming webhooks trigger package resync on push`

---

### Task W3: Events + ausgehende Zustellung (DeliverWebhook + Listener)

**Files:**
- Create: `app/Events/{PackageSynced,PackageSyncFailed}.php`, `app/Listeners/DispatchOutgoingWebhooks.php`, `app/Jobs/DeliverWebhook.php`
- Modify: `app/Jobs/SyncPackage.php` (Events feuern), ggf. `bootstrap/app.php` oder ein EventServiceProvider für das Listener-Wiring (Laravel 12 auto-discovery nutzen).
- Test: `tests/Feature/Webhook/OutgoingWebhookTest.php`

**Events:** `PackageSynced(Package $package)`, `PackageSyncFailed(Package $package, string $error)` (einfache Event-Klassen mit `Dispatchable`).

**SyncPackage:** nach erfolgreichem Sync `PackageSynced::dispatch($this->package)`; im catch-Block (vor rethrow) `PackageSyncFailed::dispatch($this->package, $e->getMessage())`.

**DispatchOutgoingWebhooks** (Listener auf beide Events): ermittelt das Event-Enum, lädt alle `enabled` Webhooks, die es abonnieren, und `DeliverWebhook::dispatch($webhook, $eventValue, $payload)` je Hook. Payload = `{event, package: {name, type, sync_status}, occurred_at}`.

**DeliverWebhook** (Queue-Job, `$tries=3`, `backoff()`): POSTet `payload` an `webhook->url` mit Headern `X-Kontorfix-Event`, `X-Kontorfix-Signature` (= `sha256=`+hash_hmac über den JSON-Body mit `webhook->secret`), legt eine `WebhookDelivery`-Zeile an (status_code, success, attempts, error, delivered_at). Bei Nicht-2xx: Delivery als Fehlversuch loggen und Exception werfen (→ Retry); beim finalen Versuch bleibt `success=false`.

- [ ] **Step 1: Failing test**

```php
<?php // tests/Feature/Webhook/OutgoingWebhookTest.php
use App\Enums\WebhookEvent;
use App\Events\PackageSynced;
use App\Jobs\DeliverWebhook;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('queues a delivery for each subscribed webhook when a package syncs', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    Webhook::factory()->for($org)->create(['events' => [WebhookEvent::PackageSynced->value], 'enabled' => true]);
    Webhook::factory()->for($org)->create(['events' => [WebhookEvent::SyncFailed->value], 'enabled' => true]); // nicht abonniert
    Webhook::factory()->for($org)->create(['events' => [WebhookEvent::PackageSynced->value], 'enabled' => false]); // deaktiviert

    PackageSynced::dispatch(Package::factory()->create());

    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('delivers a signed payload and logs a successful delivery', function () {
    Http::fake(['hooks.test/*' => Http::response('', 200)]);
    $wh = Webhook::factory()->create(['url' => 'https://hooks.test/kfx', 'secret' => 'sec', 'events' => [WebhookEvent::PackageSynced->value]]);
    $pkg = Package::factory()->create(['name' => 'acme/demo']);

    (new DeliverWebhook($wh, WebhookEvent::PackageSynced->value, ['event' => 'package.synced', 'package' => ['name' => 'acme/demo']]))->handle();

    Http::assertSent(function ($r) {
        $expected = 'sha256='.hash_hmac('sha256', $r->body(), 'sec');
        return $r->hasHeader('X-Kontorfix-Signature', $expected) && $r->hasHeader('X-Kontorfix-Event', 'package.synced');
    });
    expect($wh->deliveries()->latest()->first())->success->toBeTrue()->status_code->toBe(200);
});

it('logs a failed delivery and rethrows for retry on non-2xx', function () {
    Http::fake(['hooks.test/*' => Http::response('boom', 500)]);
    $wh = Webhook::factory()->create(['url' => 'https://hooks.test/kfx', 'secret' => 'sec', 'events' => [WebhookEvent::PackageSynced->value]]);

    expect(fn () => (new DeliverWebhook($wh, 'package.synced', ['event' => 'package.synced']))->handle())
        ->toThrow(\RuntimeException::class);
    expect($wh->deliveries()->latest()->first()->success)->toBeFalse();
});
```

- [ ] **Step 2: FAIL**, **Step 3: Implement** (Event-Auto-Discovery in Laravel 12 findet den Listener automatisch, wenn er die Events typisiert; sonst explizit registrieren). **Step 4: grün + pint + phpstan** (achte auf SyncPackage-Tests: die feuern jetzt Events — mit `Event::fake()` in bestehenden Tests? Nein — Events dispatchen echte Listener, die aber ohne Webhooks nichts tun; die Sync-Tests bleiben grün, ggf. `Queue::fake` schon aktiv). **Step 5: Commit** — `feat: outgoing webhooks with hmac signing, retry and delivery log`

---

### Task W4: Admin-GUI — Webhooks + Delivery-Log + Incoming-Setup

**Files:**
- Create: `app/Http/Controllers/Admin/WebhookController.php`, `app/Http/Requests/Admin/StoreWebhookRequest.php`, `resources/js/pages/admin/webhooks/Index.vue`
- Modify: `routes/web.php`, `AppSidebar.vue`
- Test: `tests/Feature/Admin/WebhookCrudTest.php`

- [ ] CRUD für ausgehende Webhooks (index/store/destroy) im etablierten Muster (vgl. UpstreamController): url (`url:https`), events (Multiselect der WebhookEvent-Fälle), secret (optional, nie im Index-Payload — nur `has_secret`), enabled. Index zeigt zusätzlich die letzten Deliveries pro Hook (event, status_code, success, Zeit) und die **Incoming-Webhook-URLs** je Provider (`{app_url}/webhooks/github` etc.) plus einen Hinweis, dass `KONTORFIX_INCOMING_WEBHOOK_SECRET` gesetzt sein muss. Role-gated. Tests: anlegen/validieren/löschen, member 403, secret nie im Payload. GUI: Tabelle + Dialog + Delivery-Log-Ansicht; `ddev npm run build`/`lint` grün. Sidebar-Eintrag „Webhooks" (Icon `Webhook`) im canManage-Block.
- [ ] **Commit** — `feat: webhook management gui with delivery log and incoming setup`

---

### Task W5: E2E-Contract-Test

**Files:**
- Test: `tests/Feature/Webhook/WebhookFlowTest.php`

- [ ] **Step 1:** Ende-zu-Ende (mit `Http::fake` für den ausgehenden Hook, `Queue`-sync/`Bus::fake` je nach Bedarf): ein simulierter GitHub-Push (gültige Signatur) → das Paket wird synchronisiert (Fixture-Repo) → das `PackageSynced`-Event feuert → eine `DeliverWebhook`-Zustellung an einen abonnierten Hook mit gültiger HMAC-Signatur. Plus: fehlgeschlagener Sync → `SyncFailed`-Event → Zustellung an einen `sync.failed`-Abonnenten. (Die Queue ggf. synchron ausführen, damit der Sync das Event wirklich feuert.)
- [ ] **Step 2: grün.** **Step 3: Commit** — `test: end-to-end incoming push -> resync -> outgoing webhook`

---

## Self-Review (beim Schreiben)

1. **Spec-Coverage §8:** eingehend GitHub/GitLab/Gitea/Bitbucket → Resync (W2) ✓, secret-validiert (W2, HMAC/Token) ✓; ausgehend Events → Endpoints mit HMAC-Signatur (W3) ✓, Retry-Policy (W3, $tries+backoff) ✓, Delivery-Log (W1/W3) ✓, Test-Button/GUI (W4) ✓, custom Payload — bewusst NICHT (Twig-Transformer wie Packeton) in v0.5. Ein-/ausgehende Setup-Anzeige in der GUI (W4) ✓.
2. **Sicherheit:** eingehender Endpoint ist öffentlich, aber HMAC/Token-gated (401 ohne gültige Signatur), 503 wenn kein Secret konfiguriert (kein offenes Trigger-Tor). RepoUrl-Matching triggert nur Syncs bereits registrierter Pakete (kein Anlegen). Ausgehende Secrets `encrypted` gecastet, nie im GUI-Payload; HMAC über den exakten Body. Kein CSRF auf den externen Endpoints (bewusst, signatur-gated).
3. **Netzwerk in Tests:** ausschließlich `Http::fake`/`Queue::fake`.
4. **Typkonsistenz:** `WebhookEvent`-Werte einheitlich; `DeliverWebhook(Webhook, string $event, array $payload)`; `RepoUrlMatcher::normalize/match`; `IncomingPayloadParser::verify/repoUrl`. Delivery-Felder (status_code/success/attempts/error/delivered_at) konsistent zwischen W1-Schema und W3-Job.
5. **Bestehende Tests:** SyncPackage feuert jetzt Events — die Sync-Job-Tests bleiben grün, weil ohne konfigurierte Webhooks der Listener nichts einreiht; wo nötig `Event::fake()` ergänzen, ohne die Assertion-Semantik zu ändern.

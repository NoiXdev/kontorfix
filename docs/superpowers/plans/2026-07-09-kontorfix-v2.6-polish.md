# Kontorfix v2.6 – Feinschliff (Registry-Management-Hub + gebrandete Mail) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Registry-Detailseite wird zum Management-Hub — Domains und Upstreams lassen sich dort direkt hinzufügen/entfernen (statt auf separaten Seiten). Und die Nutzer-Einladungs-Mail bekommt ein Kontorfix-Branding (Farben, Absender-Optik).

**Architecture:** `DomainController` und `UpstreamController` sind bereits `group_id`-basiert und antworten mit `back()` — die Store/Destroy-Endpunkte werden von der Registry-Detailseite (`admin/groups/Show.vue`) direkt genutzt; nach der Aktion rendert Inertia die Show-Seite neu (frische Domains/Upstreams via `GroupController@show`). Kein neues Backend für PF1. Für PF2 wird das Laravel-Mail-Theme gepublisht und auf die Markenpalette (Seenacht/Kupfer) gestellt.

**Tech Stack:** Laravel 12 (Notifications/Mail-Theme), Inertia v2 + Vue 3, Pest, Pint, Larastan L6.

---

## File Structure

- Modify `resources/js/pages/admin/groups/Show.vue` — Inline-Formulare für Domains + Upstreams.
- Modify `resources/js/pages/admin/groups/Index.vue` (nur falls nötig) — bleibt.
- Publish/modify `resources/views/vendor/mail/html/themes/default.css` (oder eigenes Theme) — Markenfarben.
- Modify `app/Notifications/UserInvitation.php` — ggf. `->theme(...)`/Markdown.
- Tests: `tests/Feature/Admin/RegistryDomainUpstreamInlineTest.php`.

---

### Task PF1: Registry-Detail = Management-Hub für Domains + Upstreams

Vorhandene Endpunkte (wiederverwenden, `back()`-basiert): `admin.domains.store` (Felder `group_id`, `hostname`) / `admin.domains.destroy`; `admin.upstreams.store` (Felder `group_id`, `type`, `url`, `policy` — exakte Felder aus `app/Http/Requests/Admin/StoreUpstreamRequest.php` + `resources/js/pages/admin/upstreams/Index.vue` übernehmen) / `admin.upstreams.destroy`.

**Files:** `resources/js/pages/admin/groups/Show.vue`, Test `tests/Feature/Admin/RegistryDomainUpstreamInlineTest.php`.

- [ ] **Step 1: Failing test** (Integration: über die Endpunkte hinzufügen, dann Show-Seite prüfen — belegt, dass der Hub-Flow schlüssig ist)
```php
<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    $this->group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
});

it('adds a domain to the registry and shows it on the detail page', function () {
    $this->actingAs($this->admin)->post('/admin/domains', ['group_id' => $this->group->id, 'hostname' => 'packages.kadenz.test'])
        ->assertRedirect();

    $this->actingAs($this->admin)->get("/admin/groups/{$this->group->id}")
        ->assertInertia(fn ($p) => $p->where('domains', fn ($d) => in_array('packages.kadenz.test', $d, true)));
});

it('adds an upstream to the registry and shows it on the detail page', function () {
    $this->actingAs($this->admin)->post('/admin/upstreams', [
        'group_id' => $this->group->id, 'type' => 'composer', 'url' => 'https://packagist.org', 'policy' => 'proxy',
    ])->assertRedirect();

    $this->actingAs($this->admin)->get("/admin/groups/{$this->group->id}")
        ->assertInertia(fn ($p) => $p->has('upstreams', 1));
});
```
Prüfe die exakten Upstream-Store-Pflichtfelder in `StoreUpstreamRequest` (z.B. `type`/`url`/`policy`, evtl. `priority`/`enabled`/`auth_token`) und ergänze im Test die MINIMAL nötigen, damit der POST 302 (kein 422) liefert. Assertionsaussage (Domain/Upstream erscheint auf der Show-Seite) unverändert lassen.

- [ ] **Step 2:** Run → prüfen: falls die Endpunkte schon alles leisten, ist der Test evtl. sofort grün (dann ist PF1 rein Frontend). Falls rot, Ursache melden.

- [ ] **Step 3: Vue** `admin/groups/Show.vue` — in den bestehenden Abschnitten „Domains" und „Upstreams" je ein Inline-Add-Formular + Entfernen-Buttons ergänzen:
  - **Domains:** Input `hostname` + „Hinzufügen" → `router.post(route('admin.domains.store'), { group_id: group.id, hostname }, { preserveScroll: true })`; pro Domain ein „Entfernen" → `router.delete(route('admin.domains.destroy', domain.id), { preserveScroll: true })`. Dafür muss `GroupController@show` die Domains als `{id, hostname}` liefern (aktuell nur `pluck('hostname')`) — **ergänze** in `GroupController@show` die Domain-`id` (map zu `{id, hostname}`) und passe die `domains`-Prop-Nutzung in Show.vue an. Der bestehende Test in `GroupDetailTest`/E2E prüft `domains.0` == hostname-String → dieser würde durch die Struktur­änderung brechen; passe daher AUCH jene Assertions additiv an (oder liefere `domains` als Objekte und aktualisiere die betroffenen Tests auf `domains.0.hostname`). Wähle EINE konsistente Form und ziehe alle betroffenen Tests nach.
  - **Upstreams:** kleines Formular (type-Select composer/npm, url, policy-Select proxy/strict — Felder wie in `upstreams/Index.vue`) → `router.post(route('admin.upstreams.store'), { group_id: group.id, ... })`; „Entfernen" → `admin.upstreams.destroy`.
  - Bestehende Anzeige-Abschnitte + Pakete/Tokens/Setup unverändert.

- [ ] **Step 4:** `ddev exec vendor/bin/pest tests/Feature/Admin/RegistryDomainUpstreamInlineTest.php` grün; **alle** von der `domains`-Strukturänderung betroffenen Tests (`GroupDetailTest`, `RegistryDetailPortalFilterEndToEndTest`, evtl. Portal) grün; `ddev exec npm run build` + `ddev exec npm run lint:check` sauber; Pint + PHPStan (falls PHP geändert).
- [ ] **Step 5:** Commit `feat: manage domains and upstreams inline on the registry detail page`.

---

### Task PF2: Gebrandete Einladungs-Mail

**Files:** `resources/views/vendor/mail/html/themes/kontorfix.css` (oder `default.css` überschreiben), `config/mail.php` (`markdown.theme`), `app/Notifications/UserInvitation.php` (falls Theme gesetzt wird), Test-Ergänzung optional.

- [ ] **Step 1:** Mail-Theme publishen: `ddev exec php artisan vendor:publish --tag=laravel-mail` (legt `resources/views/vendor/mail/**` an, u.a. `html/themes/default.css`).

- [ ] **Step 2:** Ein Marken-Theme `resources/views/vendor/mail/html/themes/kontorfix.css` anlegen (Kopie von `default.css`) und die Kernfarben auf die Kontorfix-Palette stellen: Button-/Link-Farbe Backstein-Kupfer `#D07A45`, Header-/Akzentfarbe Seenacht `#0D141F`, Grundfläche hell/`#E9E4D9`-Töne dezent. Insbesondere die `.button-primary`/`.button`-Hintergrundfarbe auf `#D07A45` und die Header-Überschrift/Link-Farbe anpassen. In `config/mail.php` `'markdown' => ['theme' => 'kontorfix', ...]` setzen (bzw. am `MailMessage` `->theme('kontorfix')`).

- [ ] **Step 3:** `UserInvitation::toMail` — sicherstellen, dass es das Theme nutzt (entweder global via config oder `->theme('kontorfix')`), und den Text-Rahmen prüfen (Subject/Greeting nutzen bereits `config('app.name')` = Kontorfix). Optional den Salutation-/Footer-Text auf „Kontorfix" setzen.

- [ ] **Step 4: Verifikation** — die Mail muss ohne Fehler rendern. Ein leichter Test:
```php
<?php

use App\Models\User;
use App\Notifications\UserInvitation;

it('renders the branded invitation mail with a set-password action', function () {
    $user = User::factory()->create();
    $mail = (new UserInvitation)->toMail($user);
    $rendered = $mail->render();

    expect($rendered)->toContain('Passwort setzen'); // Action-Button
    expect((string) $rendered)->not->toBeEmpty();
});
```
(`->render()` kompiliert das Markdown inkl. Theme → wirft, wenn das Theme kaputt ist.)

- [ ] **Step 5:** `ddev exec vendor/bin/pest` betroffene Tests grün; Pint + PHPStan (falls PHP geändert).
- [ ] **Step 6:** Commit `feat: brand the invitation email with the kontorfix palette`.

---

### Task PF3: Volle Suite

- [ ] **Step 1:** Volle Suite `ddev exec vendor/bin/pest` → alle grün (Gesamtzahl melden). `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run build`, `ddev exec npm run lint:check`.
- [ ] **Step 2:** Falls in PF1 Tests wegen der `domains`-Struktur angepasst wurden, sicherstellen, dass alles konsistent grün ist. Commit nur, falls es hier noch offene Änderungen gibt (sonst überspringen).

---

## Self-Review

- **Deckt den Feinschliff:** Domains/Upstreams direkt auf der Registry-Detailseite verwaltbar (Wiederverwendung getesteter Endpunkte); Einladungs-Mail im Marken-Look.
- **Sicherheit:** keine neuen Endpunkte in PF1 (bestehende operator-gated Domain/Upstream-Controller); Mail-Theme ist rein kosmetisch.
- **Risiko/Aufmerksamkeit:** die `domains`-Prop-Strukturänderung (String→Objekt) berührt bestehende Tests — konsistent nachziehen (PF1 Step 4). Kein weiterer Umbau.
- **Verschoben:** OCI (Phase 2).

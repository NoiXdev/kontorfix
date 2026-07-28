# Kontorfix REST-API + API-Browser + Robot-Accounts — Design-Spec

**Status:** Freigegeben (Brainstorming abgeschlossen 2026-07-28)
**Nächster Schritt:** writing-plans → Implementierungsplan

## Ziel

Ein **dritter Zugriffskanal** neben dem Registry-Protokoll (Composer/npm) und der Inertia-GUI: eine **vollständige REST-Management-API** (`/api/v1`) für Integrationen/Automatisierung, ein **auto-generierter interaktiver API-Browser**, und **Robot-/Service-Accounts** als nicht-menschliche Identitäten.

Reihenfolge im Gesamtvorhaben: **(1) diese API-Runde**, danach separat **(2) Security-Audit + Repo/Doku public-fähig**.

## Nicht-Ziele

- Kein Ersatz des Registry-Protokolls (Composer/npm-Pull/Publish bleiben unverändert über `kfx_`-Tokens).
- Keine öffentliche Freigabe des API-Browsers in dieser Runde (Gating-Entscheidung fällt in Runde 2).
- Kein GraphQL, kein JSON:API-Standard — schlankes JSON-REST.

---

## Kern-Entscheidungen (aus dem Brainstorming)

1. **Voller CRUD-Umfang** über alle Ressourcen; API-Keys tragen eine Basis-Berechtigung **read/write**.
2. **Eigener API-Key-Typ** (getrennt von Registry-`kfx_`-Tokens), gebunden an einen Nutzer- **oder Robot-Account**; erbt dessen **Rolle & Org**. Effektive Rechte = **Rolle ∩ Key-Permission**.
3. **Account-Typ `robot`**: API-only, kein interaktiver Login, trägt eine Rolle. Operator-Invariante bleibt.
4. **Auto-generierte OpenAPI + eingebauter Browser** (Scramble) unter `/docs/api`.

---

## ① Zugriffskanal & Konventionen

- **Datei/Routing:** neue `routes/api.php`, in `bootstrap/app.php` via `api: __DIR__.'/../routes/api.php'` + `apiPrefix: 'api'` registriert. Alle Endpunkte unter **`/api/v1/…`** (explizite Versionsgruppe im Route-File).
- **Stateless:** keine Cookies/CSRF (wie `routes/registry.php`). Auth ausschließlich `Authorization: Bearer kfxapi_…`.
- **Format:** reines JSON. Antworten über **Eloquent API Resources** (`app/Http/Resources/Api/…`), damit interne Felder (`token_hash`, `password`, `key_hash`) nie leaken.
- **Fehler:** Laravel-Standard `{ "message": string, "errors"?: { feld: string[] } }`; korrekte HTTP-Codes (400/401/403/404/409/422/429).
- **Paginierung:** Längen-Paginator (`data`, `links`, `meta`) mit `?page=`/`?per_page=` (Default 25, Max 100). Konsistente `?q=`/Filter-Parameter wo sinnvoll (an vorhandene GUI-Filter angelehnt).
- **Rate-Limit:** benannter Limiter `api` (z. B. **120 req/min pro Key**, Fallback pro IP für unauth), `429` mit `Retry-After`. Definiert in einem `RateLimiter::for('api', …)`.
- **Versionierung:** `/api/v1` fest im Prefix. Breaking Changes → künftige `v2`-Gruppe (Nicht-Ziel dieser Runde).

## ② Auth & Berechtigung

### Datenmodell `api_keys`
| Feld | Typ | Notiz |
|---|---|---|
| `id` | uuid v7 | HasUuids |
| `user_id` | uuid FK → users, `cascadeOnDelete` | Besitzer (Mensch **oder** Robot) |
| `name` | string(190) | frei wählbar |
| `key_hash` | string, unique, `$hidden` | `hash('sha256', $plain)` |
| `permission` | enum `read`\|`write` (`ApiKeyPermission`) | Deckelung |
| `last_used_at` | datetime nullable | von `api.auth` gesetzt |
| `expires_at` | datetime nullable | optionaler Ablauf |
| timestamps | | |

- **Klartext:** `kfxapi_` + 40 Zeichen `Str::random`. Analog `RegistryToken::issue()`: `ApiKey::issue(User $owner, string $name, ApiKeyPermission $perm, ?DateTimeInterface $expiresAt): [ApiKey, string $plain]`. Klartext **nur einmalig** (Flash bei GUI, JSON-Feld `plain_text` nur in der Create-Response).
- **Lookup:** `ApiKey::findByPlainText()` (sha256 + Ablaufprüfung), setzt `last_used_at`.

### Middleware `api.auth` (`AuthenticateApiKey`)
1. Bearer-Token extrahieren → `ApiKey::findByPlainText()`; sonst `401`.
2. Besitzer laden; wenn `null`/deaktiviert → `401`.
3. **Als Besitzer authentifizieren** (`auth()->setUser($owner)` bzw. `Auth::onceUsing`), damit die vorhandenen Gates `operator`/`role:…` unverändert greifen.
4. **Permission-Gate:** bei `permission == read` alle nicht-`GET`/`HEAD`-Methoden mit `403` ablehnen (eigene Middleware `api.write` bzw. inline im `api.auth`). `write` erlaubt alle Methoden.
5. `last_used_at = now()`.

### Autorisierungs-Schichten (kumulativ)
```
Bearer-Key gültig?  → api.auth (sonst 401)
Methode ≤ Key-Perm? → read-Key nur GET (sonst 403)
Rolle/Operator?     → dieselben operator + role:… Gates wie GUI (sonst 403)
Ressourcen-Policy?  → bestehende Policies (z. B. RegistryTokenPolicy) für Objektbesitz
```
→ **Effektiv = Rolle ∩ Key-Perm ∩ Policy.** Beispiel: `write`-Key eines `member` kann Pakete seiner Org nicht anlegen (Paket-Verwaltung ist operator-gated), aber eigene Registry-Tokens im Portal-Scope verwalten.

## ③ Account-Typ `robot`

- **Migration:** `users.account_type` enum `human`\|`robot` (`AccountType`), Default `human`, additiv. `users.password` **nullable** machen (Robots haben keins) — additive Schema-Änderung, bestehende Logins unberührt.
- **Login-Sperre:** im Web-Auth-Flow (Login-Controller / Passkey / OIDC) Robots ablehnen („Robot-Accounts können sich nicht interaktiv anmelden"). Robots erscheinen nicht in Session-basierten Flows.
- **Rolle & Org:** Robot trägt `role` (admin/maintainer/member) und `organization_id` wie ein Mensch → **Operator-Invariante gilt unverändert** (privilegierte Rollen nur in Operator-Org; `StoreUser`/`UpdateUser`-Regeln greifen auch für Robots).
- **Verwaltung:** Operator-Admin legt Robots an (Admin → Robots, s. ⑥) und stellt deren API-Keys aus.

## ④ Ressourcen & Endpunkte (`/api/v1`)

Alle Endpunkte hinter `api.auth`; zusätzlich die **gleichen** `operator`/`role:…`-Gates wie die entsprechende GUI-Route. Geschäftslogik wird mit der GUI **geteilt** (gemeinsame Validierungsregeln + schlanke Action/Service-Klassen), damit API und GUI nicht divergieren.

**Für admin/maintainer (Operator-Bereich):**
- `packages` — GET index (Filter `q`,`type`), GET show, POST (create+sync), DELETE; `POST packages/{id}/resync`.
- `groups` (Registries) — GET index/show, POST, PUT, DELETE.
  - `groups/{id}/domains` — GET/POST/DELETE.
  - `groups/{id}/upstreams` — GET/POST/DELETE.
  - `groups/{id}/packages` — GET, PUT (Zuordnung setzen).
- `registry-tokens` — GET/POST/DELETE (die `kfx_`-Pull/Publish-Tokens; Owner-Regeln wie GUI).
- `webhooks` — GET/POST/DELETE.
- `status` — GET (Sync-/Queue-Kennzahlen).

**Nur Operator-Admin:**
- `organizations` — GET/POST/DELETE.
- `users` — GET/POST/PUT/DELETE (inkl. Rollen-Invariante).
- `robots` — GET/POST/DELETE (Convenience über `users` mit `account_type=robot`).
- `api-keys` — GET/POST/DELETE für beliebige Nutzer/Robots (Operator-Admin); Menschen verwalten **eigene** Keys zusätzlich über Settings (⑥).

**Selbstverwaltung (jeder authentifizierte Key-Besitzer):**
- `me` — GET (eigenes Profil: id, name, role, org, account_type).
- `me/api-keys` — GET/POST/DELETE (eigene API-Keys; `write`-Key nötig zum Anlegen/Löschen).

Antworten via `Api\*Resource`. Konsistente Ressourcen-Namen = Route-Segmente.

## ⑤ API-Browser (Auto-Doku)

- **Tooling:** Scramble (`dedoc/scramble`) generiert OpenAPI aus Routen/FormRequests/Resources.
- **Endpunkte:** `/docs/api` (interaktiver Browser mit „Try it") + `/docs/api.json` (OpenAPI-Dokument). Self-hosted Assets (kein CDN).
- **Gating (Runde 1):** hinter Operator-Login (Scramble-`middleware`/`gate` auf `operator`). Öffentliche Freigabe = Entscheidung in Runde 2.
- **Qualität:** FormRequests + typisierte API-Resources sorgen für aussagekräftige Schemas; kurze Endpoint-Beschreibungen als PHPDoc.

## ⑥ Verwaltung in der GUI

- **Settings → API-Keys** (persönlich, alle Menschen): eigene Keys erstellen (Name + read/write, optional Ablauf) / widerrufen; Klartext einmalig (Muster wie die bestehende „Zugriffstokens"-Seite). Klar getrennt dargestellt von den Paket-Pull-Tokens.
- **Admin → Robots** (Operator-Admin): Robot-Accounts anlegen (Name, Org, Rolle), deren API-Keys ausstellen/widerrufen, Liste mit `last_used_at`.
- Sidebar: „API-Keys" unter „Zugriff" bzw. in den Settings; „Robots" unter „Verwaltung".

## ⑦ Fehler, Sicherheit & Tests

- **Secrets nie im Klartext** außer der einmaligen Create-Response; Resources verstecken `key_hash`/`token_hash`/`password`.
- **Autorisierungs-Matrix-Tests (Pest, Feature):**
  - read-Key: GET 200, jede Mutation 403.
  - write-Key `member`: eigene Portal-Scope-Mutationen ok; operator-gated 403.
  - write-Key `maintainer`/`admin`: erlaubte Ressourcen 200; Operator-Invariante (member-Admin bekommt keine Operator-Rechte) bleibt.
  - `401` bei fehlendem/ungültigem/abgelaufenem Key.
  - Robot: `api.auth` ok; interaktiver Login **abgelehnt**.
  - Rate-Limit `429` nach Überschreitung.
  - Cross-Org: Key aus Org A kann Ressourcen aus Org B nicht sehen/ändern.
- **Spec-Smoke-Test:** `/docs/api.json` liefert gültiges OpenAPI (200, enthält erwartete Pfade).
- **Abschluss:** volle Suite grün, Pint/PHPStan L6/ESLint; **adversariales Opus-Security-Review** (Fokus: Key-Isolation, Rollen-∩-Perm-Umgehung, Secret-Leak in Resources, Robot-Login-Bypass, Doku-Gating).

---

## Betroffene/neue Dateien (Überblick)

**Backend**
- `routes/api.php` (neu), `bootstrap/app.php` (api-Routing + `api.auth`-Alias + RateLimiter).
- `app/Enums/ApiKeyPermission.php`, `app/Enums/AccountType.php` (neu).
- Migrationen: `create_api_keys_table`, `add_account_type_to_users_table`, `make_users_password_nullable`.
- `app/Models/ApiKey.php` (neu, `issue()`/`findByPlainText()`); `app/Models/User.php` (+`account_type`, `apiKeys()`, `isRobot()`).
- `app/Http/Middleware/AuthenticateApiKey.php` (neu).
- `app/Http/Controllers/Api/V1/…` (je Ressource), `app/Http/Resources/Api/…`, `app/Http/Requests/Api/…`.
- Web-Auth-Flows: Robot-Login-Sperre (Login/Passkey/OIDC-Controller).
- Scramble-Config (`config/scramble.php`) mit Gate.

**Frontend**
- `resources/js/pages/settings/ApiKeys.vue` (+ Settings-Nav), `resources/js/pages/admin/robots/Index.vue` (+ Controller/Routen), Sidebar-Eintrag.

**Tests**
- `tests/Feature/Api/…` (Autorisierungs-Matrix je Ressource), `tests/Feature/Api/ApiKeyAuthTest.php`, `tests/Feature/Api/RobotAccountTest.php`, `tests/Feature/Api/DocsSpecTest.php`, `tests/Unit/ApiKeyIssueTest.php`.

## Offene Punkte für Runde 2 (Public-Readiness)

- Öffentliche Freigabe von `/docs/api` (Gating lockern) + öffentliche Read-Endpunkte für public Registries.
- Security-Audit (Secrets, Headers, Dependency-Scan), README/Doku entlang der CLAUDE.md-Regeln (keine Tech-Stack-Begriffe in außenwirksamer Copy), Lizenz, Repo public schalten.

# Kontorfix — Design-Spec

**Datum:** 2026-07-08
**Status:** Entwurf, vom Auftraggeber im Brainstorming freigegeben
**Repo:** NoiXdev/kontorfix (privat, Open-Source-Release als spätere Option)

## 1. Vision

Kontorfix ist eine selbst-gehostete **„One Registry"-Lösung**: ein Server, der private
Package-Registries für mehrere Ökosysteme (v1: Composer + npm, Phase 2: OCI) unter
einer gemeinsamen, vollständig GUI-gesteuerten Verwaltung bereitstellt — mit
Mandantenfähigkeit nach dem Vorbild des Packagist.com-Vendor-Modells: Pakete werden
zentral publiziert, Kunden zugewiesen, und jeder Kunde erhält eigene Registry-URLs,
Domains und Zugangs-Tokens.

Kontorfix ersetzt damit den Parallelbetrieb von Packeton (Composer) und Verdaccio (npm)
und behebt deren dokumentierte Kernschwächen (siehe Abschnitt 2).

**Nicht-Ziele (v1):** OCI/Docker-Registry (Phase 2, Harbor bleibt solange im Einsatz),
Hochverfügbarkeits-Cluster-Setup (Design ist stateless-fähig, Betrieb v1 = Single-Node
Compose), Paket-Publishing durch Kunden (Kundensicht ist read-only).

## 2. Lessons Learned aus Packeton & Verdaccio

Aus der Analyse beider Projekte (Features, Issues, Architektur) abgeleitete Leitplanken:

| # | Lektion | Quelle | Konsequenz für Kontorfix |
|---|---------|--------|--------------------------|
| 1 | State in JSON-Files/Memory verhindert Skalierung & HA | Verdaccio #1459/#1921, Packeton #304 | DB ist einzige Wahrheit für den Metadaten-Index; Artefakte ausschließlich über Storage-Abstraktion; App stateless |
| 2 | Config-only-Betrieb (YAML + Neustart) erzeugt Support-Last | Verdaccio #804/#1431, Packeton YAML-Bereich | Alles per GUI **und** REST-API konfigurierbar: Domains, Upstreams, Storage, User, OIDC, Webhooks |
| 3 | ACL verstreut in Controllern führt zu Leaks | Packeton #28/#84/#208 | Autorisierung in genau einer Schicht vor jeder Metadaten-/Dist-Auslieferung; ACL-Testmatrix als Pflicht-Testsuite |
| 4 | Sub-Repos als „gefilterte Views" sind brüchig, ohne Selbstverwaltung | Packeton #320 | Echte Mandanten (Organizations) mit eigenen Usern, Tokens, Statistiken, Domains |
| 5 | Reine JWTs sind nicht revozierbar | Verdaccio #1702 | Registry-Tokens gehasht in DB, sofort revozierbar, optionales Ablaufdatum |
| 6 | Fest verdrahtete OAuth-Provider driften | Packeton #360/#242/#364 | Generischer OIDC-Adapter, Provider per GUI konfigurierbar |
| 7 | Proxy/Mirror ist die Bug-Quelle Nr. 1 | Packeton #343/#330/#328 | Contract-Tests gegen reale Upstream-Typen; Sync-Fehler sichtbar in GUI |
| 8 | Setup-DX entscheidet über Support-Aufwand | Packeton #333/#262/#261 | Gute Defaults, Healthchecks, Secrets-aus-File, klare Fehlermeldungen, Statusseite |
| 9 | Plugin-/Modul-Verträge früh versionieren | Verdaccio v6-Migration | Registry-Typen als Module gegen ein stabiles internes Interface |

## 3. Stack

- **Backend:** Laravel (aktuelle Major-Version, Stand Juli 2026: 13), PHP 8.4
- **Frontend:** Inertia.js v2 + Vue 3 + Tailwind CSS 4; UI-Komponenten auf reka-ui/shadcn-vue-Basis (kein Admin-Panel-Framework — eigenes, modernes UI)
- **DB:** PostgreSQL — **alle Primärschlüssel als UUID (v7)**, auch in Pivots/Foreign Keys
- **Queue/Cache:** Redis + Laravel Horizon
- **Storage:** Flysystem (local, S3/Minio; per GUI konfigurierbar)
- **Qualität:** Pest (Tests), Pint (Format), Larastan (Static Analysis)
- **Dev:** DDEV (Projektname = Repo-Name), **Deploy:** Docker-Image + Compose via Portainer

## 4. Domänenmodell

- **Organization (Mandant):** die eigene Org (Betreiber) + Kunden-Orgs. User gehören
  zu Organizations mit Rollen. Kunden-Orgs verwalten ihre eigenen Tokens selbst.
- **Package:** protokoll-typisiert (`composer` | `npm`, später `oci`). Quelle:
  VCS-Repository (Auto-Sync via Webhook/Poll), Artifact-Upload oder Mirror (aus Upstream
  gecacht). Besitzt Versions/Releases mit Metadaten + Verweis auf Dist-Artefakt im Storage.
- **Gruppe (Registry / Sub-Repository):** konfigurierbarer Endpoint. **Alle Pakete
  leben im globalen Pool auf Root-Ebene** und werden Gruppen nur zugewiesen (n:m,
  optional mit Versions-/Datums-Obergrenze — „Lizenz abgelaufen"-Szenario). Jede Gruppe
  ist erreichbar über ihren **Slug** (Pfad auf der Hauptdomain, z.B. `/r/<slug>`)
  **und/oder eine oder mehrere eigene Domains** — beides frei wählbar. Gruppen können
  optionale Upstreams haben. **Eine Gruppe bedient Composer und npm gleichzeitig auf
  demselben Endpoint** (Protokoll-Pfade kollidieren nicht) — die „One Registry"-Idee.
  **Flüssige Zuweisung aus der GUI:** beim Anlegen eines Pakets lassen sich Gruppen
  inline auswählen oder direkt neu erstellen; beim Anlegen einer Gruppe lassen sich
  Pakete inline suchen und zuweisen — kein Kontextwechsel nötig.
- **Domain:** Host → Gruppen-Mapping; Auflösung per Host-Header-Middleware. TLS
  terminiert der Reverse Proxy (Traefik/Portainer) davor.
- **Token:** gehashtes, sofort revozierbares Registry-Token; Scope = Registry-Bindung +
  Rechte (read/publish); gehört einem User oder einer Organization; optionales Ablaufdatum.
- **Upstream:** Proxy-Kanal (packagist.org, registry.npmjs.org, beliebige weitere, auch
  mit Auth) mit Policy: alles durchleiten & cachen **oder** Strict-Mode
  (Allowlist freigegebener Pakete — Schutz gegen Dependency Confusion).
- **Webhook (in/out), AuditLog, StorageDisk, OidcProvider:** siehe Abschnitte 6–8.

## 5. Registry-Typ-Abstraktion (Erweiterbarkeit)

Jeder Registry-Typ ist ein Modul gegen ein gemeinsames internes Interface:

1. Routen-Registrierung (protokollspezifische Endpoints)
2. Metadaten-Renderer (DB → Protokollformat)
3. Client-Auth-Adapter (wie authentifiziert das jeweilige CLI)
4. Upstream-Client (Proxy/Cache-Logik)
5. Publish-/Ingest-Handler

**Composer-Modul:** `packages.json` mit `metadata-url` (Composer-API v2, v1-Fallback);
Metadaten dynamisch aus der DB, gefiltert nach Registry-Zuweisung + Token-Rechten.
Dists (Zips) aus VCS gebaut oder aus Cache/S3 gestreamt — immer durch die App mit
Auth-Check, nie als Direktlink.

**npm-Modul:** Metadaten-Dokument (`GET /:package` mit Versionen + dist-tags),
Tarball-Streaming, `PUT` für publish (intern/Maintainer), dist-tags,
Token-Endpoints (`npm token`-Kompatibilität). Tarball-URLs werden auf die jeweilige
Registry-Domain umgeschrieben.

**Proxy/Cache (beide Module):** Anfragen für nicht-private Pakete gehen bei aktiviertem
Upstream lazy an den Upstream; Metadaten + Artefakte werden on-demand gecacht
(Cache-Artefakte im Flysystem-Storage, Cache-Index in der DB). Upstream nicht erreichbar
⇒ Cache liefert weiter aus (Offline-Fähigkeit). Policy pro Registry: alles / Strict-Allowlist.

## 6. Auth & Rechtesystem

**Web-Login (drei Verfahren, alle per GUI verwaltbar):**
1. E-Mail/Passwort + TOTP-Zweifaktor
2. Passkeys (WebAuthn)
3. OIDC/SSO über generischen, GUI-konfigurierbaren Adapter (Authentik, Keycloak, GitHub, …)

**Rollen:**
- **Admin** — alles (Betreiber-Org)
- **Maintainer** — Pakete anlegen/syncen/publizieren
- **Org-Member (Kunde)** — read-only Kundensicht: eigene Registries, eigene Tokens
  anlegen/revozieren, Setup-Anleitungen, Paketliste

Feinere Rechte pro Organization/Registry über Laravel-Policies.

**Registry-Clients:** Composer via HTTP-Basic (`user:token`), npm via Bearer-Token
(`_authToken`). Tokens siehe Domänenmodell (gehasht, revozierbar, gescoped).

## 7. Storage & Hintergrund-Jobs

- Storage-Backends (local, S3/Minio, weitere S3-kompatible) als per GUI konfigurierbare
  Disks; Artefakte, Mirror-Cache und generierte Dists liegen ausschließlich dort.
- Horizon-Queues für: VCS-Sync (Webhook- oder Poll-getriggert), Mirror-Sync, Dist-Builds,
  Webhook-Zustellung. Fehlgeschlagene Jobs mit Retry-Policy und sichtbarem
  Dead-Letter-Bereich in der GUI.

## 8. Webhooks

- **Eingehend:** GitHub/GitLab/Gitea/Bitbucket-Push → Paket-Resync (Endpoint pro Paket
  oder Org-weit, Secret-validiert).
- **Ausgehend:** Events (`package.published`, `version.released`, `registry.updated`,
  `token.created`, `sync.failed`, …) an konfigurierbare Endpoints mit HMAC-Signatur,
  Retry-Policy und Delivery-Log inkl. Test-Button in der GUI.

## 9. GUI

**Admin-Bereich (Betreiber):** Dashboard (Sync-Status, Downloads, fehlgeschlagene Jobs) ·
Pakete (Submit via VCS-URL/Upload, Versionen, Sync-Log) · Registries (Domains,
Paket-Zuweisung, Upstream-Policy, Landing-Page-Text) · Organizations & User · Tokens ·
Webhooks · Storage-Settings · OIDC-Provider · Audit-Log.

**Kunden-Portal (gleiche App, gefilterte Sicht):** eigene Registries mit fertigen
Setup-Snippets (composer.json / .npmrc), Token-Selbstverwaltung, Paketliste mit
Readmes/Versionen.

**Statusseite:** Queue-Health, Storage-Erreichbarkeit, Upstream-Status.

## 10. Fehlerbehandlung

- Registry-Endpoints antworten strikt protokollkonform (korrekte 401/403/404-Semantik
  mit aussagekräftigen Meldungen — Composer/npm-CLIs zeigen sonst kryptische Fehler).
- Sync-/Mirror-Fehler landen sichtbar in der GUI (pro Paket letzter Sync-Status + Log),
  werden nie still verschluckt.
- Upstream down ⇒ Cache liefert weiter aus.

## 11. Testing

- **Protokoll-Contract-Tests:** echte `composer install`- / `npm install`-Flows gegen die
  App auf HTTP-Ebene als Kern-Testsuite.
- **ACL-Testmatrix:** Rolle × Registry-Zuweisung × Token-Scope gegen Metadaten- und
  Dist-Endpoints (die historischen Packeton-Leaks als explizite Testfälle).
- Feature-Tests für GUI-Flows; Unit-Tests für Metadaten-Renderer und Version-Parsing.
- Framework: Pest; neue Logik kommt mit Tests (Haus-Stil).

## 12. Deployment & CI

- **Ein Docker-Image** (Multi-Stage: Composer-Deps + Vite-Assets), App via
  FrankenPHP/Octane; Worker (Horizon) und Scheduler als separate Container aus demselben
  Image.
- **Compose-Stack:** `app`, `worker`, `scheduler`, `postgres`, `redis` (+ optional
  `minio`). Env-Konfiguration nur fürs Bootstrapping (DB, App-Key, Erst-Admin) — alles
  Weitere per GUI. Healthchecks, Secrets-aus-File-Support.
- **Registry für Images:** Harbor (`harbor.cloud.noidee.dev`), Deploy via Portainer.
- **CI (GitHub Actions):** Conventional-Commit-Gate, Pint, Larastan, Pest, Docker
  Build & Push.

## 13. Branding

- **Name:** Kontorfix — das hanseatische Handelskontor als Bild für den zentralen
  Umschlagplatz von Paketen; fügt sich in die NoiXdev-Namensfamilie (mailrelaynix,
  notefix, marketix, inventorix). Kollisionsprüfung 2026-07-08: keine Software-,
  Registry- oder Paketnamens-Kollision.
- **Logo/CI:** freigegeben am 2026-07-08 — Bildmarke „Der Speichergiebel"
  (Treppengiebel, Backstein-Kupfer auf Seenacht-Blau, Grünspan als Sekundärton),
  Typografie Bricolage Grotesque + JetBrains Mono, UI dark-first.
  Assets und Token-Tabelle: `docs/brand/`.
- Öffentliche Außendarstellung (falls später OSS): keine Tech-Stack-Details in
  Landingpage-Copy (Haus-Regel); technische Doku darf alles benennen.

## 14. Phasenplan

1. **v0.1:** Kern-Datenmodell, Composer-Modul (private Pakete), Admin-GUI-Basis, Tokens, eine Domain
2. **v0.2:** npm-Modul, Multi-Domain, Kunden-Portal
3. **v0.3:** Proxy/Mirror (Composer + npm), Strict-Mode, Webhooks ein-/ausgehend
4. **v0.4:** OIDC + Passkeys, S3-GUI-Config, Statusseite, Polish
5. **Phase 2:** OCI-Modul (gleiches Registry-Typ-Interface)

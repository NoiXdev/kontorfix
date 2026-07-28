# Entwicklung & Betrieb

Technische Dokumentation für Kontorfix. Hier dürfen Stack-Details benannt werden (die
außenwirksame README bleibt bewusst technikneutral).

## Architektur

- **Backend:** Laravel 12 (PHP 8.2+), ausgeliefert über FrankenPHP.
- **Frontend:** Inertia.js v2 + Vue 3 + TypeScript, Tailwind CSS 3, shadcn-vue.
- **Daten:** PostgreSQL 17 (UUID-v7-Primärschlüssel), Redis (Cache + Queue).
- **Betrieb:** Laravel Horizon (Queue-Dashboard), Reverb (Live-Updates via WebSockets),
  Scheduler (periodischer Re-Sync + Cleanup).
- **Registry-Protokolle:** Composer v2 (`packages.json`, `p2/*.json`, Dist-Download) und
  npm (Packument, Tarball, Publish). Zusätzlich eine REST-Management-API unter `/api/v1`
  mit auto-generierter, interaktiver Dokumentation unter `/docs/api` (nur Operator-Admin).

Registry- und Webhook-Endpunkte laufen bewusst **stateless** (außerhalb der `web`-Middleware-
Gruppe, ohne Cookies/CSRF) und sind ausschließlich über Token- bzw. Signaturprüfung
abgesichert.

## Lokale Umgebung (DDEV)

```bash
ddev start
ddev composer install
ddev exec npm install
ddev exec php artisan key:generate
ddev exec php artisan migrate --seed
ddev exec npm run dev
```

Nützliche Kommandos (alle über `ddev exec …`):

```bash
ddev exec vendor/bin/pest                 # Testsuite
ddev exec vendor/bin/pint                 # Code-Style (Laravel Pint)
ddev exec vendor/bin/phpstan analyse      # Static Analysis (Larastan, Level 6)
ddev exec npm run lint                    # ESLint
ddev exec npm run build                   # Frontend-Build
```

## Verzeichnisstruktur (Kurzüberblick)

- `app/Http/Controllers/{Registry,Api/V1,Admin,Portal,Auth,Settings}` — Endpunkte je Bereich.
- `app/Services/{Upstream,Registry,Storage,Health,...}` — Geschäftslogik/Services.
- `app/Http/Middleware` — u. a. `AuthenticateRegistry`, `AuthenticateApiKey`, `EnsureOperator`,
  `EnsureUserRole`, `SecurityHeaders`, `RejectRobotWebSession`.
- `resources/js/pages` — Inertia/Vue-Seiten (Admin, Portal, Settings).
- `routes/{web,api,registry,webhooks,auth,settings,console,channels}.php` — Routing.
- `docker/` — Container-Entrypoint (Rollen app/worker/scheduler/reverb) + Compose.

## Mandanten- & Rollenmodell

- **Operator-Invariante (sicherheitskritisch):** die privilegierten Rollen `admin`/`maintainer`
  existieren ausschließlich in der Betreiber-Organisation (`is_operator = true`). Kunden sind
  `member`. Erzwungen über `EnsureOperator` auf dem gesamten `/admin`-Bereich und in den
  `Store/UpdateUserRequest`-Regeln.
- **Account-Typen:** `human` (interaktiver Login) und `robot` (nur API-Key, kein interaktiver
  Login — gesperrt in Password/2FA/OIDC-Flows plus globale `RejectRobotWebSession`-Middleware).

## Deployment & Härtung

Kontorfix läuft hinter einem Reverse Proxy (z. B. Traefik). Folgende ENV-Werte beim
produktiven Deploy setzen:

- **`TRUSTED_PROXIES`** — auf die **konkrete(n) Proxy-IP(s)** pinnen, nicht auf die breiten
  Default-Privatbereiche. Die `X-Forwarded-*`-Header werden nur von diesen Adressen akzeptiert;
  bei zu weiter Konfiguration ließe sich die Client-IP (und damit IP-basierte Rate-Limits sowie
  der Host in generierten URLs) fälschen. Zusätzlich sicherstellen, dass der App-Port **nur**
  über den Proxy erreichbar ist (Netzsegmentierung).
- **`SECURITY_HSTS=true`** — sobald TLS am Proxy terminiert.
- **`SESSION_SECURE_COOKIE=true`** — Session-Cookie nur über HTTPS.
- **`SECURITY_CSP_REPORT_ONLY=true`** — Content-Security-Policy zunächst im Report-Only-Modus
  ausrollen, Verstöße auswerten (Inertia/Vite-Kompatibilität), danach auf Durchsetzung umstellen.
- **`APP_DEBUG=false`** in Produktion.

### Speicher (Storage)

Der Artefakt-Speicher (`local` oder S3/MinIO) wird vom Operator-Admin konfiguriert und gilt
als vertrauenswürdige Infrastruktur. Zwei bewusste Eigenschaften:

- Der S3-**Endpoint** wird nicht gegen interne Adressen gefiltert — ein internes MinIO im
  Container-Netz ist der Regelfall. Da nur der höchstprivilegierte Operator-Admin diese
  Konfiguration setzt, ist das ein akzeptiertes Restrisiko (kein niedrigprivilegierter Akteur
  kann den Endpoint beeinflussen).
- Eine fehlerhafte S3-Konfiguration kann Downloads unterbrechen (die `artifacts`-Disk wirft
  bei Fehlern). Vor dem Speichern den eingebauten Verbindungstest nutzen.

## Bekannte Restrisiken / Follow-ups

Aus dem Security-Audit bewusst als niedrig eingestuft und dokumentiert (nicht blockierend):

- **DNS-Rebinding (TOCTOU):** Die SSRF-Prüfung (`UrlSafety::isSafeResolving`) und der spätere
  tatsächliche Verbindungsaufbau lösen den Hostnamen getrennt auf. Vollständig dicht nur mit an
  cURL gepinntem Resolver (`CURLOPT_RESOLVE`).
- **Offene Selbstregistrierung:** `/register` erlaubt das Anlegen eines `member`-Kontos ohne
  Organisation (sieht im Portal nichts). Für eine geschlossene Instanz kann `/register` gated
  oder deaktiviert werden.
- **OIDC-E-Mail-Verknüpfung:** Auto-Linking über mehrere aktivierte Identitätsprovider für
  Member-Konten — nur relevant bei mehreren, teils nicht vertrauenswürdigen IdPs.
- **API-Existenzorakel:** Route-Model-Binding läuft vor der Key-Auth; nicht existierende
  `{id}`-Routen liefern 404 statt 401. Wegen nicht enumerierbarer UUIDs geringer Wert.
- **API-Doku in `local`:** `/docs/api` ist in der `local`-Umgebung ungated (Entwicklung).
- **JWKS-Cache:** OIDC lädt JWKS je Callback neu (Perf/Robustheit, kein Sicherheitsproblem).

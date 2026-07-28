# Development & Operations

Technical documentation for Kontorfix. Stack details may be named here (the outward-facing
README stays deliberately technology-neutral).

## Architecture

- **Backend:** Laravel 12 (PHP 8.2+), served via FrankenPHP.
- **Frontend:** Inertia.js v2 + Vue 3 + TypeScript, Tailwind CSS 3, shadcn-vue.
- **Data:** PostgreSQL 17 (UUID v7 primary keys), Redis (cache + queue).
- **Operations:** Laravel Horizon (queue dashboard), Reverb (live updates over WebSockets),
  Scheduler (periodic re-sync + cleanup).
- **Registry protocols:** Composer v2 (`packages.json`, `p2/*.json`, dist download) and
  npm (packument, tarball, publish). Plus a REST management API under `/api/v1` with
  auto-generated, interactive documentation at `/docs/api` (operator admins only).

Registry and webhook endpoints run deliberately **stateless** (outside the `web` middleware
group, without cookies/CSRF) and are secured solely by token or signature verification.

## Local environment (DDEV)

```bash
ddev start
ddev composer install
ddev exec npm install
ddev exec php artisan key:generate
ddev exec php artisan migrate --seed
ddev exec npm run dev
```

Useful commands (all via `ddev exec …`):

```bash
ddev exec vendor/bin/pest                 # Test suite
ddev exec vendor/bin/pint                 # Code style (Laravel Pint)
ddev exec vendor/bin/phpstan analyse      # Static analysis (Larastan, level 6)
ddev exec npm run lint                    # ESLint
ddev exec npm run build                   # Frontend build
```

## Directory layout (overview)

- `app/Http/Controllers/{Registry,Api/V1,Admin,Portal,Auth,Settings}` — endpoints per area.
- `app/Services/{Upstream,Registry,Storage,Health,...}` — business logic/services.
- `app/Http/Middleware` — incl. `AuthenticateRegistry`, `AuthenticateApiKey`, `EnsureOperator`,
  `EnsureUserRole`, `SecurityHeaders`, `RejectRobotWebSession`.
- `resources/js/pages` — Inertia/Vue pages (admin, portal, settings).
- `routes/{web,api,registry,webhooks,auth,settings,console,channels}.php` — routing.
- `docker/` — container entrypoint (roles app/worker/scheduler/reverb) + Compose.

## Tenancy & role model

- **Operator invariant (security-critical):** the privileged roles `admin`/`maintainer`
  exist exclusively in the operator organization (`is_operator = true`). Customers are
  `member`. Enforced by `EnsureOperator` across the entire `/admin` area and in the
  `Store/UpdateUserRequest` rules.
- **Account types:** `human` (interactive login) and `robot` (API key only, no interactive
  login — blocked in the password/2FA/OIDC flows plus a global `RejectRobotWebSession`
  middleware).

## Deployment & hardening

Kontorfix runs behind a reverse proxy (e.g. Traefik). Set the following ENV values for a
production deployment:

- **`TRUSTED_PROXIES`** — pin to the **concrete proxy IP(s)**, not the broad default private
  ranges. The `X-Forwarded-*` headers are only accepted from these addresses; with too broad
  a configuration the client IP (and thus IP-based rate limits as well as the host in
  generated URLs) could be spoofed. Also make sure the app port is reachable **only** through
  the proxy (network segmentation).
- **`SECURITY_HSTS=true`** — once TLS is terminated at the proxy.
- **`SESSION_SECURE_COOKIE=true`** — session cookie over HTTPS only.
- **`SECURITY_CSP_REPORT_ONLY=true`** — roll out the Content-Security-Policy in report-only
  mode first, evaluate violations (Inertia/Vite compatibility), then switch to enforcement.
- **`APP_DEBUG=false`** in production.

### Storage

The artifact storage (`local` or S3/MinIO) is configured by the operator admin and is treated
as trusted infrastructure. Two deliberate properties:

- The S3 **endpoint** is not filtered against internal addresses — an internal MinIO on the
  container network is the normal case. Since only the highest-privileged operator admin sets
  this configuration, this is an accepted residual risk (no lower-privileged actor can
  influence the endpoint).
- A broken S3 configuration can interrupt downloads (the `artifacts` disk throws on errors).
  Use the built-in connection test before saving.

## Known residual risks / follow-ups

Deliberately classified as low and documented in the security audit (non-blocking):

- **DNS rebinding (TOCTOU):** the SSRF check (`UrlSafety::isSafeResolving`) and the actual
  later connection resolve the hostname separately. Fully closed only with a resolver pinned
  to cURL (`CURLOPT_RESOLVE`).
- **Open self-registration:** `/register` allows creating a `member` account without an
  organization (which sees nothing in the portal). For a closed instance, `/register` can be
  gated or disabled.
- **OIDC email linking:** auto-linking across multiple enabled identity providers for member
  accounts — relevant only with multiple, partly untrusted IdPs.
- **API existence oracle:** route model binding runs before the key auth; non-existent
  `{id}` routes return 404 instead of 401. Low value due to non-enumerable UUIDs.
- **API docs in `local`:** `/docs/api` is ungated in the `local` environment (development).
- **JWKS cache:** OIDC reloads the JWKS on each callback (performance/robustness, not a
  security issue).

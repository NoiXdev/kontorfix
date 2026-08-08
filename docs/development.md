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
  the proxy (network segmentation) — the shipped `docker/compose.yaml` publishes no port on
  the host for exactly that reason; attach the proxy's network and route to `app:8080`. For a
  host-local deployment without a proxy, uncomment the loopback `ports:` line instead.
- **`SECURITY_HSTS=true`** — once TLS is terminated at the proxy.
- **`SESSION_SECURE_COOKIE=true`** — session cookie over HTTPS only.
- **`SECURITY_CSP=report`** — roll out the Content-Security-Policy in report-only mode first,
  evaluate violations (Inertia/Vite compatibility), then switch to `SECURITY_CSP=enforce`.
  Default `off`. The legacy `SECURITY_CSP_REPORT_ONLY=true` still selects `report`.
  The policy is emitted only on HTML documents — JSON/API and registry download responses
  get the universal headers (`X-Content-Type-Options`, `Referrer-Policy`, HSTS) but no CSP,
  `X-Frame-Options` or `Permissions-Policy`, none of which mean anything on a tarball.
  `@routes` (the one inline script in the layout) is nonced, so enforcement does not blank
  the SPA; the policy allows `fonts.bunny.net` for the webfont stylesheet and `ws:`/`wss:`
  for Reverb.
- **`APP_DEBUG=false`** in production.

The application's own knobs (`KONTORFIX_*`, see `config/kontorfix.php`) are listed with
their defaults in `.env.example` and `docker/.env.example`. Two of them fail closed and are
worth reading before a rollout: **`KONTORFIX_VCS_ALLOWED_HOSTS`** (a git server on a private
network must be named here or its syncs fail) and **`KONTORFIX_SETUP_REQUIRE_TOKEN`** (leave
unset — an empty value parses as false and opens the first-run wizard).

The outbound address policy (`UrlSafety`) fails closed on hosts it cannot resolve: a git
remote, upstream, webhook target or OIDC issuer that does not resolve **from inside the
container** is refused rather than attempted, because a host this application cannot resolve
is one whose address it cannot check (numeric encodings such as `0x7f000001` reach loopback
without DNS ever being consulted). So the container needs working DNS for every outbound
target, and a git host that is deliberately not in public DNS belongs in
`KONTORFIX_VCS_ALLOWED_HOSTS`.

The container runs as **`www-data` (uid 33)**, not root. Everything the app writes
(`storage`, `bootstrap/cache`, Caddy's `/data` and `/config`) is owned by that user in the
image, and a freshly created `artifacts` volume inherits the ownership from it.

> **Upgrading an existing deployment:** a volume created while the container still ran as
> root keeps its root-owned directories, and uploads/proxy caching will fail with
> "Permission denied". Chown it once, then start normally:
>
> ```bash
> docker run --rm -v <project>_artifacts:/data alpine chown -R 33:33 /data
> ```

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

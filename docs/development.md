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

- **`APP_URL`** — the single most load-bearing value here. Every absolute URL the
  application generates is rooted at it (`URL::forceRootUrl` in `AppServiceProvider`), so a
  password-reset, e-mail-verification or invitation link cannot be redirected to an
  attacker's domain by a forged `Host` header. It also seeds the `Host` allowlist below. Set
  it to the public URL, with scheme, before the first boot.
- **Trusted hosts** — the application refuses (HTTP 400) any request whose `Host` is not the
  `APP_URL` host or one of its subdomains, a loopback name (`localhost`, `127.0.0.1`, `::1`
  — the container healthcheck and the host-local deployment need these), or a hostname
  attached to a registry group in the admin UI. That list is assembled in
  `App\Services\Http\TrustedHosts`; it needs no configuration, and it stands down entirely
  when `APP_URL` names no host so a missing variable cannot lock an instance out of itself.
  Attaching a domain in the UI takes effect immediately. Note this is a *different* control
  from `TRUSTED_PROXIES`: the host allowlist bites even when no forwarded header is present
  at all, so pinning the proxy IPs does not substitute for it and vice versa.
- **`TRUSTED_PROXIES`** — pin to the **concrete proxy IP(s)**, not the broad default private
  ranges. The `X-Forwarded-*` headers are only accepted from these addresses; with too broad
  a configuration the client IP (and thus IP-based rate limits) could be spoofed. Also make
  sure the app port is reachable **only** through the proxy (network segmentation) — the
  shipped `docker/compose.yaml` publishes no port on the host for exactly that reason; attach
  the proxy's network and route to `app:8080`. For a host-local deployment without a proxy,
  uncomment the loopback `ports:` line instead. Independently of this, give the proxy a
  router rule that constrains the host (`Host(...)`, not `PathPrefix('/')`), so a forged
  `Host` never reaches the container in the first place.
- **`SECURITY_HSTS=true`** — once TLS is terminated at the proxy.
- **`SESSION_SECURE_COOKIE=true`** — session cookie over HTTPS only.
- **`SECURITY_CSP=report`** — roll out the Content-Security-Policy in report-only mode first,
  then switch to `SECURITY_CSP=enforce`. Default `off`. The legacy
  `SECURITY_CSP_REPORT_ONLY=true` still selects `report`.
  - **`SECURITY_CSP_REPORT_URI`** — set this *before* starting the rollout. Without a
    collector the browser writes violations to the visiting user's own console and nothing
    reaches the operator, so `report` mode cannot be evaluated. When set, the policy carries
    `report-uri`/`report-to` and the response carries a matching `Reporting-Endpoints`
    header.
  - The policy is emitted only on HTML documents — JSON/API and registry download responses
    get the universal headers (`X-Content-Type-Options`, `Referrer-Policy`, HSTS) but no CSP,
    `X-Frame-Options` or `Permissions-Policy`, none of which mean anything on a tarball.
  - Surfaces checked under `enforce`: the SPA (`@routes`, the one inline script in the
    layout, is nonced; `fonts.bunny.net` is allowed for the webfont stylesheet and
    `ws:`/`wss:` for Reverb), the PEP 503 index and Laravel's error pages — none of them
    break. `/horizon` and `/docs/api` are rendered from vendor views whose inline scripts
    cannot be nonced, so they are served a `script-src` of their own (`'unsafe-inline'`,
    plus `unpkg.com` for the API browser's bundle) while keeping `frame-ancestors`,
    `object-src`, `base-uri` and `form-action`. `npm run dev` HMR does not work under
    `enforce`; leave the local default at `off`.
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

**A credential in an upstream URL disables the PyPI fallthrough.** `upstreams.url` is the
only place a Basic-auth mirror credential can live — `UpstreamClient` sends the dedicated
`auth_token` as a Bearer header and nothing else — and the Composer and npm proxies handle
that fine, because they fetch server-side and rewrite artifact URLs to `/proxy/...`. The
PyPI simple index does not: for an unknown project it answers with a **302**, and a
`Location` is handed to the client. On a public group that client is anonymous. So when a
Python upstream's URL carries `user:pass@`, no redirect is emitted at all (404), and
*Admin → Status* reports the upstream as failing with the reason. Removing the credential
from the redirect instead would only trade the disclosure for a 401 at the mirror while
still naming the internal host. For a credentialled Python mirror there is currently no
fallthrough; use a credential-free mirror URL, or mirror the projects locally.

The lookup itself is one container binding, `App\Services\Upstream\HostResolver`, bound to
`SystemHostResolver` in `AppServiceProvider::register()`. It is the single decision point
of the address policy for every outbound sink, so it deliberately has no public setter —
substitute it through the container, never globally. The test suite binds
`Tests\Support\FixtureHostResolver`, which resolves the internal fixture space
(`*.internal`, `*.local`, `*.consul`, any single-label name) to a **private** address and
the documented example/test TLDs to a public one, and hands everything else — numeric host
encodings in particular — to the real system resolver, because how the C library decodes
those is the property production depends on.

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

## Login guessing

`POST /login` guarantees one property: past a free allowance, the instance answers at most
four penalised guesses per five seconds — about 0.8 per second — regardless of how many
source addresses or concurrent connections the caller brings, and no anonymous traffic can
stop an account holder from signing in from a browser they have used before.

Three counters and one admission rule (`App\Http\Requests\Auth\LoginRequest`):

| Counter | Key | Effect |
|---|---|---|
| 5 / 60 s | (email, IP) | refuses outright — burning it costs the caller their own address |
| 10 free / 15 min | account, any address | sets a progressive hold, 500 ms per failure, capped at 5 s |
| 20 free / 15 min | source address, any account | same hold, credential-stuffing dimension |

The admission rule is what makes the hold a bound rather than a speed bump. A request that
owes a penalty and comes from a browser this account has never signed in from must claim
one of four instance-wide slots *before* its password is compared, and is refused when they
are all taken. Refusing after the comparison would refuse nothing — the caller has their
answer either way — and refusing on the account counter alone would be an anonymous,
targeted lockout, which is what the first version of this control shipped. The tie is broken
by `App\Services\Auth\KnownClients`: an encrypted, `httpOnly`, one-year cookie holding a
keyed digest per account that every completed authentication adds to (`RememberKnownClient`,
on the `Login` event, so passkey, OIDC, two-factor and wizard sign-ins all mark it too). It
grants nothing on its own — only a place in the queue.

**Who this can deny, and their way back.** A *first* sign-in from a new browser while an
attacker is saturating the queue against that same account is refused. That is the price of
the bound and it costs the attacker continuous traffic to sustain. It is not a dead end:
completing a password reset also marks the browser, and `POST /reset-password` is throttled
per source address only (see the `password-reset-complete` limiter), so it is the one
recovery path an attacker flooding the account cannot deny.

**What is not bounded.** Guesses inside the free allowance are not paced, so an attacker
with unlimited addresses still gets ten tries per account per 15 minutes for free, and
credential stuffing — one guess against each of many accounts — is bounded only per source
address. Making the source counter refuse would close the second and is deliberately not
done: with `TRUSTED_PROXIES` set too broadly (its shipped default is documented as such)
every user collapses onto one address and that refusal becomes an instance-wide outage.
Edge rate limiting is the control for that dimension.

## Re-authentication and session invalidation

**What `password.confirm` covers.** Every route that hands the caller a long-lived bearer
credential, or that decides who may log in from now on, sits behind it: `settings/tokens`
and `settings/api-keys` (index, store and destroy), the whole two-factor enrolment group,
`settings/passkeys`, `portal/tokens`, `admin/tokens`, `admin/robots/{user}/keys`, and — via
`ConfirmPasswordOnEmailChange` — the address field of `PATCH /settings/profile`, because the
address is the account's recovery channel. The index pages are gated as well as the POSTs,
so the prompt happens on the way into the page rather than swallowing a submitted form.

Administration is deliberately **not** gated. `PUT /admin/users/{user}` accepts
`is_super_admin` and `email`, so the `super` group is one trust boundary and gating a single
route in it would close nothing. The line is: gate where a secret is handed out, not where
configuration is changed.

**The window is session-wide and lasts three hours** (`auth.password_timeout`, Laravel's
default). One confirmation therefore unlocks *every* gated route until it expires — opening
`GET /settings/tokens` is enough to satisfy `POST /admin/robots/{user}/keys` later in the
same session. Shorten it with `AUTH_PASSWORD_TIMEOUT` (seconds) on an instance where that is
too broad; the cost is a prompt on more page loads, which is the reason the default is not
lower. There is no per-route window: `RequirePassword` reads one session key.

**Two ways to satisfy the gate without a password**, for accounts whose owner never knew
one (OIDC-provisioned, admin-invited): a passkey assertion with user verification
(`passkey.confirm`), and `POST /confirm-password/set-link`, which mails a set-password link
to the address stored on the account — never to one taken from the request.

**Guessing at the gate is metered.** Every endpoint that compares a submitted string against
the session owner's password hash — `POST /confirm-password`, `DELETE /settings/two-factor`,
`PUT /settings/password`, `DELETE /settings/profile` — goes through
`App\Services\Auth\PasswordAttemptLimiter` and shares two counters: 6 failures per source
address and 20 per account, both over 15 minutes, with a `Failed` event per miss. Both
refuse *before* the comparison — a refusal issued afterwards bounds nothing, since the
caller learns whether the guess was right either way. The account counter is the
address-independent half: without it, an attacker past the per-(user, IP) bucket simply
moves to the next source address, because sessions are not pinned to one.

Unlike `POST /login`, a pre-comparison refusal is safe here: all four routes require a
session **for the account being guessed at**, so the account bucket can only be filled by
the owner or by somebody who already holds the owner's session — never anonymously and
never by another account. The owner's cost while that is happening is up to 15 minutes with
no password confirmation (no new tokens, no two-factor change, no self-deletion); the way
out is a password reset, which evicts the attacker's session along with everything else.

**What a password change or reset invalidates, and what it does not.** Changing the hash
evicts every other web session (`AuthenticateSession` pins each session to the hash it was
created under, and `PUT /settings/password` additionally re-issues the recaller cookie for
the device doing the change). It does **not** revoke registry tokens, API keys or passkeys.
That is deliberate: they are named credentials the user can see and revoke individually,
minting them requires re-proving the password, and registry tokens in particular are machine
credentials wired into CI — destroying them on a routine rotation would turn a password
change into a build outage. A user who believes a credential leaked revokes it directly.

`AuthenticateSession` does not pin a session whose account holds no password hash at all.
The only account shape that reaches that state is a robot (`POST /admin/robots`), and a
robot is refused an interactive session both at login and, by `RejectRobotWebSession`, on
every subsequent request — so there is no session for the fail-open to fail open on. It is a
property worth re-checking if a second writer of a null password is ever introduced.

## Known residual risks / follow-ups

Deliberately classified as low and documented in the security audit (non-blocking):

- **DNS rebinding (TOCTOU):** the SSRF check (`UrlSafety::isSafeResolving`) and the actual
  later connection resolve the hostname separately. Fully closed only with a resolver pinned
  to cURL (`CURLOPT_RESOLVE`).
- **Open self-registration:** `/register` allows creating a `member` account without an
  organization (which sees nothing in the portal). For a closed instance, `/register` can be
  gated or disabled.
- **OIDC email linking:** auto-linking across multiple enabled identity providers for member
  accounts — relevant only with multiple, partly untrusted IdPs. The match is
  case-insensitive on `lower(email)`, but the column carries no functional unique index: an
  instance that already holds two case-variant addresses keeps both rows.
- **API existence oracle:** route model binding runs before the key auth; non-existent
  `{id}` routes return 404 instead of 401. Low value due to non-enumerable UUIDs.
- **API docs in `local`:** `/docs/api` is ungated in the `local` environment (development).
  The page also loads Stoplight Elements from unpkg.com and runs it on this origin without
  subresource integrity, in a session that is by definition an operator admin's. Set
  `KONTORFIX_API_DOCS_ENABLED=false` on an instance that does not need the browser.
- **API keys minted from an API key:** `POST /api/v1/me/api-keys` cannot carry
  `password.confirm` (the gate reads a session key; `/api/v1` is stateless). A successor may
  be neither wider nor longer-lived than its parent, but a parent with no expiry still mints
  successors with no expiry — bound that with `KONTORFIX_API_KEY_MAX_TTL_DAYS`.
- **No throttle on the registry protocol routes:** a cold `composer install` or `npm ci`
  legitimately issues one request per dependency at once, so any limit low enough to matter
  would break CI. The exhaustion angle is bounded directly instead: the proxy cache enforces
  its per-artifact byte cap while the artifact streams, and the Composer dist build holds a
  per-archive lock.
- **JWKS cache:** OIDC reloads the JWKS on each callback (performance/robustness, not a
  security issue).

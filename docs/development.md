# Development & Operations

Technical documentation for Kontorfix. Stack details may be named here (the outward-facing
README stays deliberately technology-neutral).

## Architecture

- **Backend:** Laravel 13 (PHP 8.4+), served via FrankenPHP.
- **Frontend:** Inertia.js v3 + Vue 3 + TypeScript, Tailwind CSS 4, shadcn-vue.
- **Data:** PostgreSQL 17 (UUID v7 primary keys), Redis (cache + queue).
- **Operations:** Laravel Horizon (queue dashboard), Reverb (live updates over WebSockets),
  Scheduler (periodic re-sync + cleanup).
- **Registry protocols:** Composer v2 (`packages.json`, `p2/*.json`, dist download) and
  npm (packument, tarball, publish). Plus a REST management API under `/api/v1` with
  auto-generated, interactive documentation at `/docs/api` (operator admins only).

Registry and webhook endpoints run deliberately **stateless** (outside the `web` middleware
group, without cookies/CSRF) and are secured solely by token or signature verification.

### Browser support floor

Tailwind CSS 4's engine generates CSS that depends on native cascade layers,
`color-mix()` and registered custom properties (`@property`), which sets a minimum
supported browser floor of **Safari 16.4+, Chrome 111+ and Firefox 128+**. The project
declares no `browserslist` entry and no Vite `build.target`, so nothing here contradicts
that floor — but it also isn't enforced or checked anywhere. This floor follows from the
CSS engine itself, not from a decision this project made; it applies as soon as
`tailwindcss` v4 is in the dependency tree, regardless of what any project-level config
says.

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

### Test isolation

The suite gives every checkout its own Postgres database and its own temp directory,
both derived from the working directory (`tests/bootstrap.php`, `Tests\Support\TestDatabase`,
`Tests\Support\TestTempDir`). The database is created on first use, so a fresh clone or
git worktree needs no setup step.

This is load-bearing, not tidiness. `RefreshDatabase` runs `migrate:fresh` once per
process; when two checkouts shared the one `testing` database, two suites running at the
same time deadlocked each other's migration (`SQLSTATE[40P01]`), leaving a half-built
schema behind — every later test that touched a dropped table then failed with
`42P01 relation … does not exist`, in whichever files the migration happened to die.
The fixtures had the same problem in `/tmp`: the cleanups glob `kfx-fixture-*`, which
matched every checkout's repositories.

Two consequences worth knowing:

- Set `DB_DATABASE` in the environment to override the derived name — CI does this, so it
  runs against the database its service container provisions.
- Each worktree leaves a `testing_<slug>_<hash>` database behind. Drop the stale ones with
  `ddev exec psql -h db -U db -d postgres -c 'drop database <name>'` when a worktree goes
  away. Two suites started from the *same* directory still share a database; run them from
  separate checkouts, or give one an explicit `DB_DATABASE`.

## Directory layout (overview)

- `app/Http/Controllers/{Registry,Api/V1,Admin,Portal,Auth,Settings}` — endpoints per area.
- `app/Services/{Upstream,Registry,Storage,Health,...}` — business logic/services.
- `app/Http/Middleware` — incl. `AuthenticateRegistry`, `AuthenticateApiKey`, `EnsureOperator`,
  `EnsureUserRole`, `SecurityHeaders`, `RejectRobotWebSession`.
- `resources/js/pages` — Inertia/Vue pages (admin, portal, settings).
- `routes/{web,api,registry,webhooks,auth,settings,console,channels}.php` — routing.
- `docker/` — container entrypoint (roles app/worker/scheduler/reverb) + Compose.

## Listing tables: `DataTable` and `useTableState`

Every listing page (fifteen of them, across seventeen backing tables) uses the same pair of
building blocks instead of a hand-rolled `<table>`:

- **`resources/js/components/kontorfix/DataTable.vue`** — the shell. It owns the filter bar
  (search input, `filters` slot, active-filter count/reset), the `<table>`/`<thead>` and the
  sortable column headers, and the empty state (no rows at all vs. no rows matching the
  current filter). **Cells stay with the page.** `DataTable` renders its default slot with
  `:rows="state.visibleRows.value"` and the page supplies the `<tr>`/`<td>` markup for its own
  columns; the component never learns what a package or a token looks like.
- **`resources/js/composables/useTableState.ts`** — the state machine. It owns sort
  key/direction, search text, filter values, the filtered/sorted row list, and syncing all of
  that into the query string. A page calls `useTableState()` with its `columns`, `rows`,
  `searchKeys` and (for server-mode pages) `filters`, and passes the returned `state` plus a
  `columns` array into `DataTable`.

### `client` vs. `server` mode

Thirteen of the fifteen listings run in **`client` mode**: the controller ships the full
dataset in the Inertia response (these tables are small — organizations, users, groups,
domains, credentials, …), and `useTableState` filters/sorts it in the browser for free.

`admin/packages` and `admin/activity` run in **`server` mode**: both paginate, and sorting a
paginated table in the browser would only reorder the rows already on the current page —
which reads as a bug, not a limitation, to whoever is looking at it. In server mode
`useTableState` skips its own sort/filter and renders `options.rows()` as received; the sort
key change is written to the query string exactly as in client mode, `router.get` reloads the
page, and the controller does the ordering in SQL. See `useTableState`'s `visibleRows`
computed for the branch, and `app/Http/Controllers/Admin/PackageController.php` /
`Admin/ActivityController.php` for the SQL side.

### State lives in the query string

Sort key/direction, search text and filter values are all mirrored into the URL (`sort`,
`direction`, `q`, plus one query param per filter). A search edit debounces before it commits;
sort and filter changes commit immediately. That makes a filtered, sorted view shareable by
URL and makes it survive a reload — and in server mode, it is *how* the request is made in the
first place.

**A page hosting two tables needs distinct `prefix` values.** Without a `prefix`, every table
on the page reads and writes the same `sort`/`direction`/`q`/filter keys, so clicking a header
in one table silently reorders — or, worse, appears to do nothing to — the other. Two pages
have this: `admin/webhooks/Index.vue` (`prefix: 'in'` / `'out'` for inbound vs. outbound
webhooks) and `portal/Registry.vue` (`prefix: 'pkg'` / `'tok'` for packages vs. tokens). Any
future page with more than one `DataTable` needs the same treatment.

**Never rebuild the query from the control that changed** — merge into it. `mergeQuery()`
(`resources/js/lib/listingQuery.ts`) starts from `window.location.search`, overwrites only the
keys it is handed, drops the ones set to `undefined` or `''`, and always removes `page`.
`useTableState` and `admin/activity` both go through it. The alternative has already been a
bug here: the activity filter bar rebuilt the whole query from its own refs and had to read
`sort` back out of the URL by hand so that changing the log name would not silently reset the
order — a workaround that would have needed repeating for every parameter added afterwards,
and that would have dropped the page size the moment one existed. Dropping `page` is part of
the same contract: after a sort, filter or page-size change, page 4 does not hold the rows it
held before.

### Server-mode sort keys are whitelisted, never interpolated

`PackageController` and `ActivityController` each keep a private `SORTABLE` map from an
accepted query-string key to the real column/alias, and `orderBy()` is only ever called with
a value taken *out of that map* — never with the request value itself, and an unrecognised
key falls back to the existing default order instead of raising. This is not defensive
style; both controllers carry a comment recording why, next to `SORTABLE`:  a malformed
value on an unrelated, differently-typed filter (`group` / `subject_id` — a plain
query-string value compared against a Postgres `uuid` column) once raised
`SQLSTATE[22P02]`, and because nothing rendered the error, every subsequent request appended
a stack trace *with its bound parameters* to an unrotated log. The sort key sits on the same
untrusted, unthrottled request, so it gets the same whitelist-and-never-interpolate
treatment rather than a character-class validation that has already been shown to have gaps.

### The page size is whitelisted the same way, and the direction reported is the one applied

`ActivityController` also keeps `PAGE_SIZES = [25, 50, 100]`. `->paginate()` is only ever
called with a value out of that list — an unlisted `per_page` falls back to 50 rather than
raising, so a stale link still renders. Same reasoning as `SORTABLE`: the parameter arrives
on an unthrottled route, and `->paginate($raw)` would let a caller ask Postgres for 100000
rows and the presenter to build 100000 arrays. The check is `ctype_digit()` *before* the int
cast, because `(int) "25abc"` is a perfectly valid 25 and `?per_page[]=…` casts to 1. The
list is also sent to the page as `pageSizes`, so the selector cannot offer an option the
server would reject.

The payload reports `per_page` and `direction` **as applied, not as requested**. The
direction one is easy to get wrong: with no `sort` key the controller falls back to
`latest('id')` — newest first — while `$direction` still holds its raw `asc` default, so the
old payload had the timeline's direction toggle labelled "Älteste zuerst" over a
newest-first list. Any control that renders server state has this hazard; report what the
query actually did.

### A timeline cannot carry sortable column headers

`admin/activity` renders `ActivityTimeline`, not `DataTable`, so the per-column sort headers
went with the table. Only the direction came back, as an explicit toggle: entries are grouped
under day headings, and grouping by day only reads in chronological order — sorting the same
list by `log_name` would produce day headings in a random order, which is worse than no
sorting. `log_name` and `description` therefore have no UI any more (the log-name filter
above the list covers the first, and `subject_type · subject_label` on each entry covers the
second), even though both remain in `SORTABLE` and reachable by query string. This is a
deliberate narrowing, not an oversight — a listing that grows a timeline gives up
per-column sorting.

### The relative-date trap

Six timestamp columns (`last_used_at` on `settings/AccessTokens`, `settings/ApiKeys`,
`admin/tokens`, `admin/git-credentials`, `portal/Registry`; `last_received_at` on
`admin/webhooks`) display a humanised relative time from `diffForHumans()` — "vor 3 Tagen" —
because that is what belongs in a table cell. `Date.parse()` cannot read that string, so
`sortAs: 'date'` on the humanised value alone would silently degrade to an alphabetical
compare over prose fragments ("vor", "Minuten", "Tagen", …), producing an order that looks
plausible at a glance and is wrong. Each of those controllers therefore also sends a
sort-only ISO twin (`last_used_at_iso`, `last_received_at_iso`); the column definition keeps
`sortAs: 'date'` but points `sortValue` at the ISO field while the template still renders the
humanised one. Anyone adding a timestamp column should check which of the two shapes they
actually have before wiring up the column — a plain `row[key]` on a relative-time field is
the bug this pattern exists to avoid.

### Nulls sort last, in both directions

A column's `sortValue` (or the default `row[key]` lookup) must return `null` — not `''` — for
a missing value. `useTableState` special-cases `null` to sort after every real value
regardless of ascending/descending, so reversing the sort direction never surfaces a screen of
dashes at the top. Returning `''` instead defeats that: an empty string still compares as a
real value, so it moves to whichever end the current direction happens to sort empty strings
to, and flips there and back as the direction toggles.

### Known gaps

`useTableState` has unit coverage (`resources/js/composables/useTableState.test.ts` —
Vitest, run via `npm run test`), and that step now runs as its own `Tests (Vitest)` step in
the `quality` job of `.github/workflows/ci.yml`, alongside `Lint (eslint)`, `Format check
(Pint)`, `Static analysis (PHPStan)` and `Tests (Pest)` — it was added there specifically
because it is the only automated check on the sort/filter rules (nulls-last, the `_iso`
mapping, `prefix` isolation) and is not something the other gates would catch if it broke.
`DataTable.vue` itself still has no test coverage of its own. Manual in-browser verification
(client-mode sort, server-mode pagination + sort, the dual-`prefix` pages) was planned but
could not be carried out in this environment — the in-app browser tool cannot load the
application — so it still wants a pass in a real browser before this is treated as fully
verified end to end.

## Admin create and edit pages

Seven `/admin` sections — `users`, `oidc`, `webhooks` (both the outgoing and the incoming
kind), `packages`, `tokens`, `upstreams`, `git-credentials` — moved their create/edit UI out
of a `<DialogContent>` on the listing page and onto their own routed pages. Two sections,
`organizations` and `domains`, deliberately kept their dialogs: three fields each, and a full
page load to type two values and click back is worse than a dialog. Do not add an eighth
migrated section's worth of ceremony to a three-field form, and do not add a twelfth dialog
to a form with more than that — check the field count against the seven vs. two split above
before choosing.

### File layout and the `Form.vue` / `Create.vue` / `Edit.vue` split

Each migrated section's directory under `resources/js/pages/admin/<section>/` holds:

- **`Create.vue` / `Edit.vue`** — one per route, each a full `AppLayout` page with its own
  breadcrumbs and `<Head title>`. Each owns the Inertia `useForm()` call (seeded empty for
  create, seeded from the loaded record for edit), the submit handler
  (`form.post(...)`/`form.put(...)`, usually via `form.transform()` to reshape the client
  model into the request payload), and the Abbrechen/submit button row. `Edit.vue` additionally
  receives the record as a prop and reads it into the form's initial state.
- **`Form.vue`** — the shared field markup (labels, inputs, selects, switches, inline errors)
  used by both `Create.vue` and `Edit.vue`. It takes a `mode: 'create' | 'edit'` prop where
  behaviour genuinely differs (e.g. a group picker disabled once an upstream exists), plus
  whatever read-only option lists it renders (`groups`, `organizations`, `providers`, …). It
  does **not** own a `useForm()` — see the next section.
- **`<x>Form.ts`** — the section's shared TypeScript module: the form's data-shape interface,
  a typed `InjectionKey` for that shape, and any payload-building/transform helper both pages
  need identically (e.g. `upstreamForm.ts`'s `buildUpstreamPayload`, shared by both submits
  rather than duplicated).

### The form travels by `provide`/`inject`, never as a prop

`Create.vue`/`Edit.vue` call `provide(xFormKey, form)` right after constructing their
`useForm()`; `Form.vue` calls `inject(xFormKey)` and throws immediately if nothing was
provided ("`Form.vue` requires a form to be provided via `xFormKey` — see Create.vue /
Edit.vue."), rather than failing silently on `undefined`. This is deliberate, not
incidental: `Form.vue`'s `v-model="form.field"` bindings write into the form object, and a
plain prop would trip the repository's `vue/no-mutating-props` ESLint rule. Weakening that
rule to allow it was attempted twice on this codebase and reverted twice — an injected value
isn't a prop as far as that rule (or Vue) is concerned, so it sidesteps the conflict rather
than fighting it. Follow the existing `<x>Form.ts` pattern for a new section instead of
re-deriving this.

### `Form.vue` may own a side-effecting request — but only for a section-specific sub-resource

`admin/users`' `Form.vue` posts to `admin.users.organizations.store`/`.destroy` directly
(attaching/detaching an organisation membership) rather than queuing that as part of the
parent form's submit. That's correct there: memberships are a distinct sub-resource with
their own lifecycle, independent of whether the name/role edit on the same page is ever
saved. **This is the one exception, not a precedent.** No other migrated section's `Form.vue`
makes a request of its own — business logic belongs in the controller/`store()`/`update()`,
reached through the one `form.post()`/`form.put()` that `Create.vue`/`Edit.vue` own. Adding a
second inline request to a future `Form.vue` needs the same justification users has: a
genuinely separate sub-resource, not a shortcut around the parent form's submit.

### Shared row and option types belong in the section's `<x>Form.ts`

Declare `interface FooOption { id: string; name: string }`-shaped types once, in the
section's `<x>Form.ts`, and import them into `Index.vue`/`Create.vue`/`Edit.vue`/`Form.vue`
rather than re-declaring the same shape in each file. Several sections currently still
redeclare `OrganizationOption` (and `users` redeclares `Membership`) in three or four files
instead of importing one; TypeScript's structural typing makes the duplication harmless today,
but it is a drift risk the moment one copy gains or loses a field and the others don't. Treat
this as the standard to converge toward, not as evidence the duplication is fine.

### New routes go in the same middleware group as the `store` they feed

`routes/web.php` groups `/admin` routes by two middleware stacks. A new `create`/`edit` GET
route belongs in whichever group already holds that resource's `store`/`update` — it must
match, not merely resemble it:

- `['auth', 'super']` — `users`, `oidc`, `webhooks` (both outgoing and incoming).
- `['auth', 'operator']` — `packages`, `tokens`, `upstreams`, `git-credentials`.

**A new GET route inherits nothing else.** The middleware group gives you the coarse
auth/role gate, but a per-record authorisation check is a separate decision that has to be
made again for every new action, including a `GET` that merely renders a form. `edit()` on
`upstreams` and `git-credentials` both needed a `$this->assertAdministersOrg(...)` call added
that `index()` never needed (index only ever lists records already scoped to the caller's
organizations) and that `update()`/`destroy()` already had. Without it, any operator-console
admin — someone who passes the `operator` middleware for their own organization — could open
another organization's upstream or git-credential edit page directly by URL. `FormPagesTest.php`
carries the regression tests for this ("refuses the upstream edit page for an admin of a
different organization" and the git-credential equivalent).

**Testing note:** `User::factory()->operator()` is not what it sounds like for these tests.
That state's home organization has `is_operator: true`, which makes the resulting user an
effective super-admin (`User::isSuperAdmin()`) regardless of role, and a super-admin passes
every per-organization scoping check (`assertAdministersOrg`, `scopeGroupQuery`) — silently
defeating a test meant to prove that scoping. `FormPagesTest.php` uses `->operator()` only for
the `users`/`oidc`/`webhooks` cases (which sit behind `super` and are supposed to see
everything); the `upstreams`/`git-credentials` cross-organization-refusal cases construct a
plain `User::factory()->create(['role' => UserRole::Admin])` instead, so the fixture actually
exercises the scoping it claims to.

### `edit()` loads from the record, never from the listing's mapped row

The listing's `index()` maps each row into exactly the shape its table needs — which may omit
fields the edit form binds to (a token's `has_auth` boolean vs. its actual presence, a
package's redacted vs. raw URL, …). `edit()` re-queries and re-maps the single record instead
of assuming the browser already has an equivalent object in memory; the edit page is also
reachable directly (a bookmark, a shared link, back/forward), where no listing state exists
at all. `git-credentials`' `edit()` additionally illustrates the mirror image of this: fields
that must be *excluded* from the fresh load (the stored token itself, write-only from the UI)
stay excluded there exactly as they were in `index()`.

### A one-time secret reveal dictates the redirect target

`admin/tokens`' and `admin/incoming-webhooks`' `store()` actions mint a plaintext secret that
only their respective index page renders (via a `flash()` value read on that page alone).
Both used to `return back()`, which — once minting moved to its own `create` page — would
return the browser to `create`, where the reveal has nowhere to render and the plaintext is
gone for good since it's never stored. Both now `redirect()->route('admin.<x>.index')`
explicitly. **Four other places use the identical `back()`-plus-flash pattern and are safe
only because they still mint from an inline form on their own index page, not a separate
create route:** `settings/tokens`, `settings/api-keys`, `portal/tokens`, `admin/robots`. Giving
any of those a dedicated create page reproduces this exact defect — check the redirect target
before splitting the form out.

### Check props against their real consumers, tests included

A dialog on the old listing page could read an option list the table itself never touched; if
that prop doesn't reach the new page, the corresponding `<select>` just renders empty — no
error, nothing to notice in a diff. Two things fell out of checking this for every migrated
section:

- `users`' `index()` needed `organizations` **added**, not merely left in place, because the
  listing's own filter uses it too — it wasn't only the dialog's dependency.
- `packages`' `index()` still sends `sourceModes` even though `Index.vue` no longer reads it
  (the create form moved to its own page and that's the only consumer now) — because
  `NpmSourceModeTest` asserts on that prop directly against `GET /admin/packages`,
  independent of the Vue component. Removing it would pass every frontend check and break
  that unrelated, pre-existing test.

Neither of these is visible from `Index.vue` alone. Grep what a controller's `index()` sends,
then check every consumer — the page's `<script setup>` *and* the test suite — before
deciding a prop is dead.

### A leading `@vue-ignore` comment silently emits no runtime props at all

`@vue/compiler-sfc` skips resolving a type node whose **leading comments contain the
substring `@vue-ignore`**. When that comment sits at the front of the whole type node
`defineProps` resolves, the compiler discards everything — the inherited members *and* the
locally declared ones — and the component ends up with no runtime props whatsoever.

Position is the trigger. Whether the type is a named `interface`, a named `type` alias or
inlined into `defineProps<…>()` makes no difference. These three emit **zero** props:

```ts
/* @vue-ignore */                             // leads the declaration
interface Props extends ButtonHTMLAttributes { variant?: string }
defineProps<Props>();

type Props = /* @vue-ignore */ ButtonHTMLAttributes & { variant?: string };
defineProps<Props>();                         // leads the intersection

defineProps</* @vue-ignore */ ButtonHTMLAttributes & { variant?: string }>();
```

Anywhere but the front is fine — an `extends` member, or any intersection member after the
first:

```ts
interface Props extends /* @vue-ignore */ ButtonHTMLAttributes { variant?: string }
interface Props extends PrimitiveProps, /* @vue-ignore */ ButtonHTMLAttributes { … }
defineProps<{ variant?: string } & /* @vue-ignore */ ButtonHTMLAttributes>();
```

Each of the seven forms above was compiled through the harness below and behaves as stated.
An earlier revision of this section blamed the named interface and prescribed inlining;
that is wrong in both directions — inlining does not help when the comment leads, and a
named interface is safe when it does not.

This is not a style rule. It shipped twice in this codebase and both times the symptom was
severe and silent:

- `Button` lost `variant`, `size`, `class` and `as`. Every button rendered with cva's
  default styling, and `as` fell back to radix's own default element — a `<div
  type="submit">`, which looks and hovers like a button and submits nothing. No form in the
  application could be submitted.
- `Input` lost `modelValue`, so `useVModel` never saw a value. Bound inputs rendered blank
  and the value landed on the DOM as a meaningless `modelvalue="…"` attribute instead of
  `value` — which meant every edit page showed empty fields.

**No type check detects this.** `vue-tsc` resolves the type on a different code path from
the runtime compiler and reports the props as present and correctly typed, so a strict type
check passes clean. ESLint, Vite, Vitest and Pest never mount a component. The first
attempt at a fix patched only the `as` symptom in the template and concluded the class of
bug was handled; it was not.

Run `npm run check:props` after touching any component's props. It compiles every component
under `resources/js/components` — `ui/` and `kontorfix/` alike — and reports the runtime
props each one emits, exiting non-zero when a component declares props through a type
argument and emits none. A component that chooses its element or binds a model is also
worth one look in a real browser — check the rendered `tagName` and that a bound value
appears as `value`, not as a stray attribute.


### An SSR harness that renders nothing passes every negative assertion

The in-app browser cannot load this application, so escaping is verified by compiling a
component with `@vue/compiler-sfc` and rendering it through `@vue/server-renderer`. That
works, but it has one silent failure mode worth knowing before you write the next one.

radix-vue's `DialogPortal` renders **nothing** under SSR unless it is force-mounted. A
harness around any dialog therefore produces an empty string, and every check of the form
"the payload does not appear as a live tag" passes — against no output at all. This
happened while building `ActivityDetailDialog`: zero occurrences of the payload, zero live
`<img>`, all green, nothing rendered. Alias `radix-vue` to a stub that re-exports the real
package with a force-mounted `DialogPortal`; everything else stays the real code.

Two rules follow, and both have caught real mistakes here:

- **Assert presence before absence.** Check that the expected content *is* in the output
  first. A negative assertion alone cannot distinguish "safe" from "empty".
- **Scope every assertion to the element it is about.** `html.includes('emerald')` matched
  the timeline's marker dot as well as its badge, so stripping the badge colouring entirely
  left the check green. The same mistake in the detail dialog matched text that `JsonViewer`
  had rendered elsewhere on the page rather than the table under test.

Choosing the pattern is harder than it looks, and two attempts here were wrong.

A naive `/\sonerror=/` matches the *escaped* text `&lt;img src=x onerror=alert(1)&gt;` and
reports a handler that does not exist. The obvious repair, `/<[a-z][^>]*\sonerror\s*=/`,
is also wrong: it holds for escaped payload in **text content**, but false-positives when
the payload sits inside an **attribute value**. Escaped output contains no literal `>`, so
`[^>]*` runs straight past the attribute boundary and reaches the inert `onerror=` inside
the escaped string — which is how a correctly escaped `pypi:project-status-reason` meta tag
was reported as an injection.

Match something that cannot survive escaping at all — the tag name itself:

```
/<img\b/i          matches a live tag, never `&lt;img …&gt;`, in text or in an attribute
```

Pair it with the presence assertion: the escaped form appears, the live tag does not.

**SSR renders what a control shows, never what it does.** Event handlers are stripped from
the server render entirely, so a button wired to nothing produces byte-identical output to a
correctly wired one — the exact "renders perfectly, does nothing" failure this file already
records twice. The compiled *client* module is where the binding is visible: with a Vite dev
server in middleware mode, `server.transformRequest('/resources/js/pages/…/Index.vue')`
returns code containing `onClick: … $setup.toggleDirection` and `"onUpdate:modelValue":
$setup.setPerPage`. Checking the parameters those functions then build belongs in Vitest, on
a composable — which is the reason `admin/activity`'s query-string state lives in
`useActivityQuery` rather than inside the page.

Two more mechanics for the next harness: user `enforce: 'pre'` plugins run *after* Vite's
alias plugin, so a stub for `@/layouts/AppLayout.vue` must match the already-resolved
absolute path; and `ssrLoadModule` externalises anything under `node_modules`, so stubbing
`@inertiajs/vue3` needs `ssr: { noExternal: ['@inertiajs/vue3'] }` or the real module loads
and `Head` fails on a missing head manager.


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
- **Verify and pin the image.** The release workflow signs every published image by digest
  with keyless cosign, bound to this repository's `release.yml` workflow identity, alongside
  the provenance attestation and SBOM it already produced. Provenance says how an image was
  built; the signature says that *this* image is the one this repository published, which is
  the part a `docker compose pull` of a mutable `:latest` cannot establish. Verify the version
  tag, read its digest, and pin `docker/compose.yaml` to `@sha256:<digest>` — the exact
  commands are in the header of that file.
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

**The Simple API version number is a claim about the payload, not a free label.**
`PythonSimpleIndexBuilder::SIMPLE_API_VERSION` is served as `meta.api-version` in the PEP 691
JSON and as both `pypi:repository-version` HTML meta tags, and it must only be raised together
with the fields the new version actually requires:

- **1.1** requires the `versions` key and makes `size` mandatory on every file — both were
  already unconditional here, which is why 1.1 was the starting point.
- **1.2** (tracks) and **1.3** (provenance) are optional per the specification and are
  deliberately absent — this registry has nothing to say about either yet.
- **1.4** adds PEP 792's `project-status` object, always present with a `status` of `active` or
  `deprecated` and, when deprecated, a `reason`. The JSON key is **`status`** despite PEP 792's
  own prose saying `state` — PyPI's live responses and the normative PyPA Simple Repository API
  specification both serve `status`, and that is what clients parse. The HTML representation
  carries the same information as `pypi:project-status`/`pypi:project-status-reason` meta tags.
  This registry never emits `archived` or `quarantined`, only `active`/`deprecated`: an
  abandonment is exactly PEP 792's definition of `deprecated` (obsolete, possibly superseded),
  not merely "no further updates expected" (`archived`) or a security incident
  (`quarantined`).

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
> "Permission denied". Git-sourced packages hit the same root cause on the same volume: their
> mirrors under `storage/app/vcs` are also root-owned and git refuses to work in a repository
> it does not own ("detected dubious ownership").
>
> A sync does recover from this on its own: it cannot delete a root-owned mirror, but it can
> rename it aside — that needs permission on `storage/app/vcs`, not inside the mirror — and
> clone a fresh one next to it. The package works again without an operator. What it leaves
> behind is a `<id>.git.foreign-<timestamp>-<random>` directory that the app can never remove,
> logged at warning level as "Displaced a foreign-owned git mirror". Those directories are
> yours to delete, and they keep a second copy of every affected mirror on the volume until
> you do.
>
> So the chown is still the fix, not the fallback. Run it once, then start normally — it
> fixes uploads, dist caching, and every git mirror in one pass, and stops any further
> displacement copies from being created:
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

### Logs

**In the Docker deployment the application does not write a log file at all.** `app`,
`worker`, `scheduler` and `reverb` each set `LOG_CHANNEL=stderr` in `docker/compose.yaml`,
so everything Laravel logs goes to the container's stderr and is read with `docker compose
logs -f app` or from the container's log view in Portainer.

| Where | Retention | Set in |
| ----- | --------- | ------ |
| Container stderr → Docker `json-file` | 10 MB × 5 files per container (~50 MB) | `logging:` in `docker/compose.yaml` |
| Local install (`storage/logs/laravel-YYYY-MM-DD.log`) | `LOG_DAILY_DAYS`, 14 days | `config/logging.php`, `.env` |
| Test suite (`storage/logs/testing-YYYY-MM-DD.log`) | `LOG_TESTING_DAYS`, 3 days | `phpunit.xml`, `config/logging.php` |

Two things this replaces, both of which were real:

- **A log file in a container is deleted by the deploy that makes you want to read it.**
  Only `storage/app` is a named volume (`artifacts`); `storage/logs` is not mounted, so a
  file channel wrote into the container's writable layer. It grew unbounded for the life of
  the container and Watchtower then destroyed it on the next image pull — precisely when an
  operator is reconstructing an incident. Mounting a volume for it would have fixed the
  destruction but not the growth, and would have put the logs somewhere you have to shell in
  to read.
- **Docker's own default is unbounded too.** The `json-file` driver with no options appends
  to `/var/lib/docker/containers/<id>/<id>-json.log` forever. Redirecting the app to stderr
  without capping the driver just moves the disk filler one layer out, so every service in
  the stack — Postgres and Redis included — declares `logging:` limits.

**To change it:**

- *Keep more or less output:* `max-size` / `max-file` under `logging:` in the stack file.
  Raise `max-file` for a longer window, `max-size` if single entries are large (stack traces
  are). Both are per container.
- *Change verbosity:* `LOG_LEVEL` in `docker/.env`. It defaults to `debug`; `info` is a
  sane production floor.
- *Ship logs elsewhere:* set the Docker `logging.driver` per service (`syslog`, `gelf`,
  `journald`, …), or point `LOG_CHANNEL` at `papertrail`/`syslog` in `config/logging.php`.
  Do not point it back at `single` or `daily` in a container, for the reason above.

Locally there is no container, so `config/logging.php` decides. Its default is `daily`, not
Laravel's stock `single` — `single` writes one file nothing ever rotates, which is how this
repository's own `storage/logs/laravel.log` reached 142 MB. Nothing prunes a file that was
already written under the old default; delete it by hand (`rm storage/logs/laravel.log`)
once, and rotation takes it from there.

The test suite has its own channel and its own file. It used to fall through to the default
one and append to the log a developer reads, and it dominated it — roughly 117k `testing.*`
records against 100 real `local.*` ones, because a full run is ~1500 tests and many drive
failure paths on purpose. It is routed rather than silenced: a test that fails for a reason
its assertion cannot show you is diagnosed from that log.

`tests/Unit/ContainerLoggingTest.php` holds all of this together — it fails if a service is
added without log limits, if an application service starts writing to a file inside its
container, if the local default stops rotating, or if the suite is pointed back at the
developer's log.

### Session and cache identity across upgrades

Three values decide whether a framework upgrade is invisible to the people using the
instance or reads as an outage, and all three used to be *derived* from `APP_NAME` rather
than written down:

| Variable       | Value            | What changing it does                                     |
| -------------- | ---------------- | --------------------------------------------------------- |
| `SESSION_COOKIE` | `kontorfix_session` | Renaming it logs out every signed-in user, mid-session. |
| `CACHE_PREFIX`   | `kontorfix_cache_`  | Renaming it orphans the cache; the instance comes back cold and re-fetches from its upstreams. |
| `REDIS_PREFIX`   | `kontorfix_database_` | Same, for everything else Redis holds under the application prefix. |

Laravel 13 changes the framework's own derivation of the **two prefixes** from
`app_name_…` to `app-name-…` (`vendor/laravel/framework/config/cache.php` and
`config/database.php` now read `Str::slug(APP_NAME).'-cache-'` and `…'-database-'`). The
session cookie is *not* part of that change — the framework's base `config/session.php`
still derives `Str::snake(APP_NAME).'_session'`.

Neither reached this instance, because all three derivations live in this repository's own
published `config/cache.php`, `config/database.php` and `config/session.php`, which win over
the framework's base config. That is a thin guarantee to rely on — a later config sync would
flip it silently — so the three values are now pinned in `.env.example` and
`docker/.env.example` instead of derived.

**They no longer follow `APP_NAME`.** Renaming the instance, or pointing a second instance
at the same Redis, means setting all three per instance; two instances sharing a prefix
share each other's cache entries and session records.

`SESSION_SERIALIZATION` is the same shape of decision. `json` is the stronger format — it
cannot instantiate a PHP object on read, so a writable session store stops being an
object-injection primitive — but `php` and `json` cannot read each other's payloads, so
switching invalidates every session exactly once. It is pinned to `php`, which is what
every existing install already runs. Moving to `json` is worth doing; do it as its own
announced change, not folded into an upgrade.

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

**What is written.** `App\Listeners\LogAuthenticationEvent` turns `Failed` into
`Authentication failed.` (guard, user id, addressee, IP, path — never the credentials, which
carry the submitted password whenever the framework's own `Auth::attempt()` raises the
event) and `Lockout` into `Authentication throttled.`, deduplicated to one line per
(address, target) per minute so an anonymous caller cannot drive one write per request.
That is what the argument below rests on: what the application declines to refuse, it at
least reports.

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

Administration is otherwise deliberately **not** gated: the `super` group is one trust
boundary and gating a single configuration route in it would close nothing. The line is:
gate where a secret is handed out or where a change outlives the caller's access, not where
configuration is changed.

`users.email` is the second kind. It is the account's password-reset channel, so whoever
moves it owns that account afterwards and *keeps* it once their own access is revoked — the
attacker ends up holding the password. Both writers are therefore covered:

- `PUT|PATCH /admin/users/{user}` carries `ConfirmPasswordOnEmailChange`, which measures the
  change against the account being edited and engages only when the address actually moves;
  role, name, home organization and the super-admin flag stay ungated.
- `PUT /api/v1/users/{user}` **refuses** an address change outright. `AuthenticateApiKey`
  admits any non-GET on a `write` key and calls `Auth::setUser()`, while `RequirePassword`
  reads a session key that does not exist there, so no gate can ever apply on that surface.
  A leaked super-admin key would otherwise convert into permanent ownership of any account
  on the instance. Everything revocation *does* undo stays writable. Use the web UI to move
  an address.

**The window is session-wide and lasts fifteen minutes** (`auth.password_timeout`;
Laravel's own default is three hours). One confirmation unlocks *every* gated route until
it expires — opening `GET /settings/tokens` is enough to satisfy
`POST /admin/robots/{user}/keys` later in the same session — so the window's length is also
how long a stolen session rides a confirmation the owner made for an unrelated reason.
Three hours is most of a working day and was not defensible for that; fifteen minutes
covers the multi-step flows the gate actually spans (open the page, fill the form, submit)
and matches the decay window of the guessing counters. `AUTH_PASSWORD_TIMEOUT` (seconds)
moves it.

**One gate asks for less.** `ConfirmPasswordOnEmailChange` requires a confirmation made in
the last **five minutes**. Every other gated surface hands out something revocable; moving
the reset channel is the one gated action with no undo — it survives losing the session,
survives changing the password back, and survives revoking every credential the attacker
holds.

**Why the window is not scoped per action.** The obvious better answer is a separate stamp
per purpose, so opening the token page cannot satisfy an address change at all. It is not
implemented because `passkey.confirm` — the escape hatch that makes the gate usable for
accounts whose owner never knew a password — lives in `laravel/passkeys` and writes the
single `auth.password_confirmed_at` key itself. A per-purpose scheme would therefore either
lose that hatch or need a shim around vendor code that silently breaks on upgrade, and
inside a fifteen-minute window the ride it would prevent is already short. Individual
middleware narrowing its own window, as above, gets most of the benefit with none of that.

**Two ways to satisfy the gate without a password**, for accounts whose owner never knew
one (OIDC-provisioned, admin-invited): a passkey assertion with user verification
(`passkey.confirm`), and `POST /confirm-password/set-link`, which mails a set-password link
to the address stored on the account — never to one taken from the request.

**The two routes that prove the password inline reach the same hatch.**
`DELETE /settings/two-factor` and `DELETE /settings/profile` want the current password in
the payload, which for those accounts is a dead end — they could enable a second factor (a
passkey satisfies that gate) and never switch it off, and could never delete themselves.
Both now carry `ConfirmPasswordUnlessSubmitted`: submit a password and it is compared
exactly as before; submit none and the request goes to the confirmation screen. It asks for
a confirmation made in the last **five minutes**, not the shared fifteen, because these two
used to prove the password on the acting request itself. Both form requests keep an
implicit `requiredIf` behind the middleware, so a route that ever loses it falls back to
demanding the password rather than to accepting an empty field.

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

## Failure-digest notifications

Background failures (a `packages:resync` that cannot reach a repository, an outgoing
webhook delivery that exhausts its retries, …) are recorded as they happen
(`App\Listeners\RecordNotificationEvent`, into `notification_events`) and mailed later in a
periodic digest (`App\Jobs\SendNotificationDigest`) rather than one-at-a-time — see
`App\Support\DigestSummary` below for why a batch, not a stream.

**Exactly once, never silently dropped, one bad recipient does not block the rest.** A
`notification_events` row is marked `notified_at` only *after* the mail carrying it has
actually been sent (`SendNotificationDigest::digestFor()` marks rows from the recipients
loop, once `Mail::send()` has returned). The send is wrapped in its own `try`/`catch`: a
mailer that throws for one recipient (an SMTP `550` at `RCPT TO`, a timeout, …) is logged
and skipped, and the loop moves on to the next recipient rather than aborting the whole run.
Rows a failed send was trying to report stay `notified_at = null`, so the next scheduled run
retries exactly them — the backlog survives a mail outage instead of being consumed by the
attempt that failed to deliver it, and a recipient who *did* receive the mail is never
re-sent it because an unrelated recipient's mailbox rejected delivery. `$reported` is only
flushed once, after the whole recipients loop — not per recipient — and the flush chunks its
`whereIn` update at 1000 ids, comfortably under PostgreSQL's ~65535 bind-parameter limit, so
a large backlog cannot make the marking update itself throw.

The same "stays pending, not consumed" property covers subscription gaps: an event whose
type no *enabled* recipient currently subscribes to is never marked `notified_at` either
(`digestFor()` only marks the events that were actually mailed to someone), so it stays in
the table indefinitely. Adding a recipient for that event type later produces a non-empty
first digest — the backlog that accumulated while nobody was subscribed — rather than
silence, which is what would happen if unreported rows were treated as consumed the moment
they were considered.

Only *reported* rows are ever deleted, and only once they are old:
`NotificationEventRecord::prunable()` (the model is `MassPrunable`) covers rows with
`notified_at` set more than 30 days ago. An unreported row is explicitly excluded from that
scope, for the same reason it is never marked in the first place — deleting it would
silently discard the exact backlog the "later recipient still sees it" rule exists to
preserve.

**Why the digest folds instead of listing.** `packages:resync` is scheduled hourly
(`routes/console.php`), so a single repository that has been unreachable for a day produces
24 near-identical failure rows by the time the next digest runs. `DigestSummary::fold()`
collapses same-(`type`, `subject`) events into one `DigestLine` — a count and the newest
message — before the digest is rendered, so a broken repository shows up as one line with
"24×", not 24 copies of the same sentence. A digest that lists instead of folds is the kind
of mail people learn to filter away rather than read.

**Per-organization cadence.** `organizations.notification_cadence` (`'hourly'` / `'daily'` /
`'off'`, set from the organization's admin page) controls how often
`SendNotificationDigest` considers an organization due: `'daily'` compares
`last_digest_sent_at` against `now()->subDay()`, anything else (including the default,
`'hourly'`) against `now()->subHour()`, and a null `last_digest_sent_at` is always due.
`'off'` organizations are excluded by the job's query before that comparison ever runs — set
per organization, it silences the mail regardless of how many enabled recipients exist,
because the query that finds due organizations never selects it in the first place.

`last_digest_sent_at` is stamped with the run's *start* time (`handle()` captures `now()`
once, before iterating organizations, and every `digestFor()` call in that run reuses it),
not the time the run finishes. Stamping the finish time would make the gap the next run's
`isDue()` sees equal to the cadence *minus* however long synchronous mail sending took —
sending is rarely free, so a "due" org would almost never actually be due, and the effective
cadence would drift outward run after run. Stamping the start time keeps the gap equal to
the cadence regardless of how long sending took.

Concurrent execution is prevented by `SendNotificationDigest` implementing
`ShouldBeUnique`, not by `Schedule::job(...)->withoutOverlapping()` in `routes/console.php`
— see the comment there for why that call alone would not be enough for a queued job.

### A Pest gotcha: `toThrow(SomeInterface::class)` does not check `instanceof`

Found while writing this branch's tests, worth its own note because the failure mode is
silent. Pest's `toThrow()` only performs an `instanceof` check when the class you pass it is
**concrete and instantiable**. For an interface (or an abstract class), `class_exists()`
returns false internally, and Pest falls back to treating the argument as a plain string —
it asserts that the thrown exception's **message** contains that string as a substring.

Concretely, `expect(fn () => ...)->toThrow(Throwable::class)` does not assert "throws
something"; it asserts that `get_message()` on whatever was thrown contains the literal text
`"Throwable"`. That assertion passes or fails for reasons that have nothing to do with the
exception's type — a `RuntimeException('caught a Throwable during sync')` satisfies it, and
a `TypeError` with an unrelated message does not, regardless of whether either is in fact a
`Throwable`.

Always pass a concrete, instantiable class (`RuntimeException::class`,
`App\Exceptions\SyncFailedException::class`, …) when the intent is a type check. Reserve the
string form of `toThrow()` for when a message substring genuinely is what you mean to
assert.

## Known residual risks / follow-ups

Deliberately classified as low and documented in the security audit (non-blocking):

- **DNS rebinding (TOCTOU):** the SSRF check (`UrlSafety::isSafeResolving`) and the actual
  later connection resolve the hostname separately. Fully closed only with a resolver pinned
  to cURL (`CURLOPT_RESOLVE`).
- **Open self-registration:** `/register` allows creating a `member` account without an
  organization (which sees nothing in the portal). For a closed instance, `/register` can be
  gated or disabled — it is disabled by default. The endpoint carries `throttle:5,1` per
  source address: it performs a bcrypt-12 hash, inserts a row and sends mail per request.
- **OIDC email linking:** auto-linking across multiple enabled identity providers for member
  accounts — relevant only with multiple, partly untrusted IdPs. The address is now one
  identity whatever its case: a unique partial index on `lower(users.email)`, a
  case-insensitive uniqueness check on every write path, and an oldest-first order in the
  resolver. An instance that already held two case-variant addresses gets a *non-unique*
  index instead of a failed deploy, with the addresses named in the log and on the operator
  health page; `php artisan users:enforce-email-uniqueness` installs the unique one once
  they are resolved.
- **API existence oracle:** route model binding runs before the key auth; non-existent
  `{id}` routes return 404 instead of 401. Low value due to non-enumerable UUIDs.
- **API docs in `local`:** `/docs/api` is ungated in the `local` environment (development).
  The page also loads Stoplight Elements from unpkg.com and runs it on this origin without
  subresource integrity, in a session that is by definition an operator admin's. Set
  `KONTORFIX_API_DOCS_ENABLED=false` on an instance that does not need the browser.
- **API keys minted from an API key:** `POST /api/v1/me/api-keys` cannot carry
  `password.confirm` (the gate reads a session key; `/api/v1` is stateless). A successor may
  be neither wider nor longer-lived than its parent, and a parent with no expiry falls back
  to `KONTORFIX_API_KEY_SUCCESSOR_MAX_TTL_DAYS` (90 days) so the chain ends rather than
  renewing itself — the console form behind `password.confirm` is unaffected and still mints
  perpetual keys. `KONTORFIX_API_KEY_MAX_TTL_DAYS` remains the instance-wide ceiling and now
  also applies to keys issued to robot accounts from the console.
- **No throttle on the registry protocol routes:** a cold `composer install` or `npm ci`
  legitimately issues one request per dependency at once, so any limit low enough to matter
  would break CI. What is bounded is the *work*, not the requests: the proxy cache enforces
  its per-artifact byte cap while the artifact streams; a per-artifact fetch lock collapses
  concurrent misses for one coordinate into a single upstream fetch; the cache evicts to make
  room instead of degrading to permanent pass-through when its budget is reached; and the
  Composer dist build holds a per-archive lock. Note that the byte budget bounds disk, not
  work — an artifact over `KONTORFIX_UPSTREAM_CACHE_MAX_ARTIFACT_BYTES` is served and never
  cached, so setting that value below what your upstreams actually ship leaves those
  coordinates permanently in pass-through, bounded only by the fetch lock.
- **Inline git credentials:** `packages.repository_token` is bound to the authority
  (host *and* port) it was entered for, and `packages.repository_url` is redacted on every
  read path including the activity log. A package whose recorded destination no longer
  matches its URL syncs unauthenticated and fails visibly; re-entering the token rebinds it.
- **JWKS cache:** OIDC reloads the JWKS on each callback (performance/robustness, not a
  security issue).

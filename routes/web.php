<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Portal\RegistryController;
use App\Http\Controllers\Portal\TokenController;
use App\Http\Controllers\SetupController;
use App\Http\Middleware\ConfirmPasswordOnEmailChange;
use App\Http\Middleware\EnsureSetupIncomplete;
use App\Http\Middleware\EnsureSetupTokenPresented;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// First-run wizard. Sealed off by EnsureSetupIncomplete the moment any user exists,
// gated by EnsureSetupTokenPresented until the setup token has been presented, and
// throttled because it is an unauthenticated account-creating endpoint. Both gates sit
// on the group so that anything added here is closed by default; the order matters,
// because "already set up" (redirect) must stay distinguishable from "token missing"
// (403).
Route::middleware([EnsureSetupIncomplete::class, EnsureSetupTokenPresented::class])->group(function () {
    Route::get('setup', [SetupController::class, 'show'])->name('setup.show');
    // Presenting the token. A POST because the token is an instance-takeover secret and
    // a query string is copied into proxy logs, APM traces and browser history; the
    // throttle is defence in depth behind a 40-character random value.
    Route::post('setup/unlock', [SetupController::class, 'unlock'])
        ->middleware('throttle:10,1')->name('setup.unlock');
    Route::post('setup', [SetupController::class, 'store'])
        ->middleware('throttle:10,1')->name('setup.store');
    // Tighter limit than the wizard submit: this one actually sends mail.
    Route::post('setup/mail-test', [SetupController::class, 'testMail'])
        ->middleware('throttle:5,1')->name('setup.mail-test');
});

Route::get('/', function () {
    // Logged-in users don't see the marketing landing page, but their workspace.
    if (request()->user()) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

// Registry surface: reachable by any organization admin/maintainer. Every controller
// here scopes its queries to the organizations the user may administer (intersected with
// the active sidebar scope) via the ScopesToAdministeredOrgs trait — so a customer-org
// admin only ever sees and touches their own registries, packages, tokens and domains.
Route::middleware(['auth', 'operator'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('packages', Admin\PackageController::class)->only(['index', 'store', 'destroy']);
    // Preview a repository (reachability + discovered name/description/versions) before
    // creating. Throttled: it shells out to git against a caller-named address and can hold
    // a worker for the full `ls-remote` timeout, so an unbudgeted loop parks the worker pool
    // — reachable by any Admin or Maintainer of any organization. The budget is per account
    // (the default key for an authenticated route), so one tenant cannot spend another's,
    // and 10/minute is far above what filling in the create dialog costs.
    Route::post('packages/probe', [Admin\PackageController::class, 'probe'])
        ->middleware('throttle:10,1')->name('packages.probe');
    Route::get('packages/{package}', [Admin\PackageController::class, 'show'])->name('packages.show');
    // Edit a package's repository source (URL, private-repo credential/token).
    Route::put('packages/{package}', [Admin\PackageController::class, 'update'])->name('packages.update');
    Route::resource('groups', Admin\GroupController::class)->only(['index', 'store', 'destroy']);
    Route::get('groups/{group}', [Admin\GroupController::class, 'show'])->name('groups.show');
    Route::put('groups/{group}', [Admin\GroupController::class, 'update'])->name('groups.update');
    // Assign/remove packages directly from the registry (group) view.
    Route::post('groups/{group}/packages', [Admin\GroupController::class, 'attachPackages'])->name('groups.packages.store');
    Route::delete('groups/{group}/packages/{package}', [Admin\GroupController::class, 'detachPackage'])->name('groups.packages.destroy');
    Route::get('package-search', Admin\PackageSearchController::class)->name('package-search');
    Route::get('search', Admin\GlobalSearchController::class)->name('search');
    Route::resource('tokens', Admin\TokenController::class)->only(['index', 'destroy']);
    // Minting is gated like `settings/tokens`: the same RegistryToken::issue() hands out a
    // long-lived bearer credential that outlives the session, so a stolen session alone
    // must not produce one. Listing and revoking stay ungated — revocation must never be
    // harder than issuance. On refusal the operator is returned to the form page.
    Route::post('tokens', [Admin\TokenController::class, 'store'])
        ->middleware('password.confirm')->name('tokens.store');
    Route::resource('upstreams', Admin\UpstreamController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('domains', Admin\DomainController::class)->only(['index', 'destroy']);
    // Reusable git access tokens (for syncing private repositories), org-scoped.
    Route::resource('git-credentials', Admin\GitCredentialController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('git-credentials/{gitCredential}/test', [Admin\GitCredentialController::class, 'test'])->name('git-credentials.test');
    // Switch the active organization scope (sidebar). Clamped server-side to the orgs the
    // user administers, so it can filter/redirect context but never widen access.
    Route::post('scope', Admin\ScopeController::class)->name('scope.set');
});

// Instance-wide administration: only the global super-admin. These surfaces have no
// per-organization dimension (or span all of them) — global system config, the user and
// organization directories, robots, outgoing/incoming webhooks and instance health.
Route::middleware(['auth', 'super'])->prefix('admin')->name('admin.')->group(function () {
    // Attaching a registry hostname is instance-wide, not per-organization: `domains.hostname`
    // is globally unique and the row alone decides whose packages are served at that host
    // (ResolveRegistryContext). The application cannot prove that a caller controls a DNS
    // name, so a customer-org admin claiming one would permanently deny it to another tenant
    // and — once that tenant's DNS points here — serve their own packages under it. Listing
    // and DETACHING stay with the owning organization (routes/web.php above): removal must
    // never be harder than creation, or a mistaken attachment needs an operator to undo.
    Route::post('domains', [Admin\DomainController::class, 'store'])->name('domains.store');

    Route::resource('webhooks', Admin\WebhookController::class)->only(['index', 'store', 'destroy']);
    // Incoming webhook endpoints (per-source secret + URL) and audit.
    Route::post('incoming-webhooks', [Admin\WebhookController::class, 'storeIncoming'])->name('incoming-webhooks.store');
    Route::post('incoming-webhooks/{incoming}/regenerate', [Admin\WebhookController::class, 'regenerateIncoming'])->name('incoming-webhooks.regenerate');
    Route::delete('incoming-webhooks/{incoming}', [Admin\WebhookController::class, 'destroyIncoming'])->name('incoming-webhooks.destroy');
    Route::get('status', [Admin\StatusController::class, 'index'])->name('status');

    Route::resource('oidc', Admin\OidcProviderController::class)->only(['index', 'store', 'destroy'])->parameters(['oidc' => 'provider']);
    Route::post('oidc/discover', [Admin\OidcProviderController::class, 'discover'])->name('oidc.discover');

    Route::get('system', [Admin\SystemController::class, 'show'])->name('system.show');
    Route::put('system', [Admin\SystemController::class, 'update'])->name('system.update');

    // Global audit log (Spatie activitylog). Scoped views are reached via query params.
    Route::get('activity', [Admin\ActivityController::class, 'index'])->name('activity.index');

    Route::get('storage', [Admin\StorageController::class, 'show'])->name('storage.show');
    Route::put('storage', [Admin\StorageController::class, 'update'])->name('storage.update');
    Route::post('storage/test', [Admin\StorageController::class, 'test'])->name('storage.test');

    Route::get('mail', [Admin\MailController::class, 'show'])->name('mail.show');
    Route::put('mail', [Admin\MailController::class, 'update'])->name('mail.update');
    Route::post('mail/test', [Admin\MailController::class, 'test'])->name('mail.test');

    Route::resource('organizations', Admin\OrganizationController::class)->only(['index', 'show', 'store', 'destroy'])->parameters(['organizations' => 'organization']);
    // Per-organization registry-type availability (restrict within the instance ceiling).
    Route::put('organizations/{organization}/registry-types', [Admin\OrganizationController::class, 'updateRegistryTypes'])->name('organizations.registry-types.update');
    // Grant/revoke additional organization access from the organization view.
    Route::post('organizations/{organization}/members', [Admin\OrganizationController::class, 'attachMember'])->name('organizations.members.store');
    Route::delete('organizations/{organization}/members/{user}', [Admin\OrganizationController::class, 'detachMember'])->name('organizations.members.destroy');

    // Moving somebody else's address is the same takeover primitive as moving your own
    // (PATCH /settings/profile carries the identical middleware): the address is the
    // account's reset channel, so whoever redirects it owns the account afterwards and
    // keeps it after their own access is revoked. The middleware engages only when the
    // address actually changes, so role and organization edits are untouched — this is
    // not the whole `super` group being gated, it is the one field that outlives the gate.
    Route::resource('users', Admin\UserController::class)->only(['index', 'create', 'store', 'edit', 'destroy']);
    Route::match(['put', 'patch'], 'users/{user}', [Admin\UserController::class, 'update'])
        ->middleware(ConfirmPasswordOnEmailChange::class)
        ->name('users.update');
    Route::post('users/{user}/invite', [Admin\UserController::class, 'invite'])->name('users.invite');
    // Grant/revoke additional organization access from the user view.
    Route::post('users/{user}/organizations', [Admin\UserController::class, 'attachOrganization'])->name('users.organizations.store');
    Route::delete('users/{user}/organizations/{organization}', [Admin\UserController::class, 'detachOrganization'])->name('users.organizations.destroy');

    Route::get('robots', [Admin\RobotController::class, 'index'])->name('robots.index');
    Route::post('robots', [Admin\RobotController::class, 'store'])->name('robots.store');
    // Same class of credential as `settings/api-keys`, so the same gate: an API key for a
    // robot is a standalone, long-lived bearer token with the robot's full reach.
    Route::post('robots/{user}/keys', [Admin\RobotController::class, 'issueKey'])
        ->middleware('password.confirm')->name('robots.keys.store');
    Route::delete('robots/{user}', [Admin\RobotController::class, 'destroy'])->name('robots.destroy');
});

Route::middleware(['auth', 'verified'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [RegistryController::class, 'index'])->name('registries.index');
    Route::get('registries/{group}', [RegistryController::class, 'show'])->name('registries.show');
    Route::get('registries/{group}/packages/{package}', [RegistryController::class, 'showPackage'])->name('registries.package');
    // The portal mints exactly the credential `settings/tokens` mints, so it carries the
    // same `password.confirm` gate — gating one surface and not the other would only tell
    // a session thief which URL to use. `destroy` stays ungated so that revoking a token
    // is never harder than minting one.
    Route::post('tokens', [TokenController::class, 'store'])
        ->middleware('password.confirm')->name('tokens.store');
    Route::delete('tokens/{token}', [TokenController::class, 'destroy'])->name('tokens.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

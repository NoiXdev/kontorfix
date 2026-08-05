<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Portal\RegistryController;
use App\Http\Controllers\Portal\TokenController;
use App\Http\Controllers\SetupController;
use App\Http\Middleware\EnsureSetupIncomplete;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// First-run wizard. Sealed off by EnsureSetupIncomplete the moment any user exists,
// and throttled because it is an unauthenticated account-creating endpoint.
Route::middleware(EnsureSetupIncomplete::class)->group(function () {
    Route::get('setup', [SetupController::class, 'show'])->name('setup.show');
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

Route::middleware(['auth', 'operator', 'role:admin,maintainer'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('packages', Admin\PackageController::class)->only(['index', 'store', 'destroy']);
    Route::get('packages/{package}', [Admin\PackageController::class, 'show'])->name('packages.show');
    Route::resource('groups', Admin\GroupController::class)->only(['index', 'store', 'destroy']);
    Route::get('groups/{group}', [Admin\GroupController::class, 'show'])->name('groups.show');
    Route::put('groups/{group}', [Admin\GroupController::class, 'update'])->name('groups.update');
    // Assign/remove packages directly from the registry (group) view.
    Route::post('groups/{group}/packages', [Admin\GroupController::class, 'attachPackages'])->name('groups.packages.store');
    Route::delete('groups/{group}/packages/{package}', [Admin\GroupController::class, 'detachPackage'])->name('groups.packages.destroy');
    Route::get('package-search', Admin\PackageSearchController::class)->name('package-search');
    Route::get('search', Admin\GlobalSearchController::class)->name('search');
    Route::resource('tokens', Admin\TokenController::class)->only(['index', 'store', 'destroy']);
    Route::resource('upstreams', Admin\UpstreamController::class)->only(['index', 'store', 'destroy']);
    Route::resource('domains', Admin\DomainController::class)->only(['index', 'store', 'destroy']);
    Route::resource('webhooks', Admin\WebhookController::class)->only(['index', 'store', 'destroy']);
    Route::get('status', [Admin\StatusController::class, 'index'])->name('status');
});

Route::middleware(['auth', 'operator', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('oidc', Admin\OidcProviderController::class)->only(['index', 'store', 'destroy'])->parameters(['oidc' => 'provider']);
    Route::post('oidc/discover', [Admin\OidcProviderController::class, 'discover'])->name('oidc.discover');

    Route::get('storage', [Admin\StorageController::class, 'show'])->name('storage.show');
    Route::put('storage', [Admin\StorageController::class, 'update'])->name('storage.update');
    Route::post('storage/test', [Admin\StorageController::class, 'test'])->name('storage.test');

    Route::get('mail', [Admin\MailController::class, 'show'])->name('mail.show');
    Route::put('mail', [Admin\MailController::class, 'update'])->name('mail.update');
    Route::post('mail/test', [Admin\MailController::class, 'test'])->name('mail.test');

    Route::resource('organizations', Admin\OrganizationController::class)->only(['index', 'show', 'store', 'destroy'])->parameters(['organizations' => 'organization']);
    // Grant/revoke additional organization access from the organization view.
    Route::post('organizations/{organization}/members', [Admin\OrganizationController::class, 'attachMember'])->name('organizations.members.store');
    Route::delete('organizations/{organization}/members/{user}', [Admin\OrganizationController::class, 'detachMember'])->name('organizations.members.destroy');

    Route::resource('users', Admin\UserController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('users/{user}/invite', [Admin\UserController::class, 'invite'])->name('users.invite');
    // Grant/revoke additional organization access from the user view.
    Route::post('users/{user}/organizations', [Admin\UserController::class, 'attachOrganization'])->name('users.organizations.store');
    Route::delete('users/{user}/organizations/{organization}', [Admin\UserController::class, 'detachOrganization'])->name('users.organizations.destroy');

    Route::get('robots', [Admin\RobotController::class, 'index'])->name('robots.index');
    Route::post('robots', [Admin\RobotController::class, 'store'])->name('robots.store');
    Route::post('robots/{user}/keys', [Admin\RobotController::class, 'issueKey'])->name('robots.keys.store');
    Route::delete('robots/{user}', [Admin\RobotController::class, 'destroy'])->name('robots.destroy');
});

Route::middleware(['auth', 'verified'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [RegistryController::class, 'index'])->name('registries.index');
    Route::get('registries/{group}', [RegistryController::class, 'show'])->name('registries.show');
    Route::get('registries/{group}/packages/{package}', [RegistryController::class, 'showPackage'])->name('registries.package');
    Route::post('tokens', [TokenController::class, 'store'])->name('tokens.store');
    Route::delete('tokens/{token}', [TokenController::class, 'destroy'])->name('tokens.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

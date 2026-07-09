<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Portal\RegistryController;
use App\Http\Controllers\Portal\TokenController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    // Angemeldete Nutzer sehen nicht die Marketing-Startseite, sondern ihren Arbeitsbereich.
    if (request()->user()) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'role:admin,maintainer'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('packages', Admin\PackageController::class)->only(['index', 'store', 'destroy']);
    Route::resource('groups', Admin\GroupController::class)->only(['index', 'store', 'destroy']);
    Route::get('package-search', Admin\PackageSearchController::class)->name('package-search');
    Route::resource('tokens', Admin\TokenController::class)->only(['index', 'store', 'destroy']);
    Route::resource('upstreams', Admin\UpstreamController::class)->only(['index', 'store', 'destroy']);
    Route::resource('domains', Admin\DomainController::class)->only(['index', 'store', 'destroy']);
    Route::resource('webhooks', Admin\WebhookController::class)->only(['index', 'store', 'destroy']);
    Route::get('status', [Admin\StatusController::class, 'index'])->name('status');
});

Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('oidc', Admin\OidcProviderController::class)->only(['index', 'store', 'destroy'])->parameters(['oidc' => 'provider']);
    Route::post('oidc/discover', [Admin\OidcProviderController::class, 'discover'])->name('oidc.discover');

    Route::get('storage', [Admin\StorageController::class, 'show'])->name('storage.show');
    Route::put('storage', [Admin\StorageController::class, 'update'])->name('storage.update');
    Route::post('storage/test', [Admin\StorageController::class, 'test'])->name('storage.test');
});

Route::middleware(['auth', 'verified'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [RegistryController::class, 'index'])->name('registries.index');
    Route::get('registries/{group}', [RegistryController::class, 'show'])->name('registries.show');
    Route::post('tokens', [TokenController::class, 'store'])->name('tokens.store');
    Route::delete('tokens/{token}', [TokenController::class, 'destroy'])->name('tokens.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

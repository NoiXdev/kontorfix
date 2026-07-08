<?php

use App\Http\Controllers\Admin;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'role:admin,maintainer'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('packages', Admin\PackageController::class)->only(['index', 'store', 'destroy']);
    Route::resource('groups', Admin\GroupController::class)->only(['index', 'store', 'destroy']);
    Route::get('package-search', Admin\PackageSearchController::class)->name('package-search');
    Route::resource('tokens', Admin\TokenController::class)->only(['index', 'store', 'destroy']);
    Route::resource('upstreams', Admin\UpstreamController::class)->only(['index', 'store', 'destroy']);
    Route::resource('domains', Admin\DomainController::class)->only(['index', 'store', 'destroy']);
    Route::resource('webhooks', Admin\WebhookController::class)->only(['index', 'store', 'destroy']);
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

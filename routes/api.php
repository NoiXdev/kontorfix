<?php

use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\GroupDomainController;
use App\Http\Controllers\Api\V1\GroupPackageController;
use App\Http\Controllers\Api\V1\GroupUpstreamController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PackageController;
use App\Http\Controllers\Api\V1\RegistryTokenController;
use Illuminate\Support\Facades\Route;

// Alle Management-Endpunkte sind stateless (Bearer-Key), versioniert unter /api/v1.
// Reihenfolge: erst api.auth (setzt das apiKey-Attribut), dann throttle:api — nur so
// kann der Limiter pro Key statt pro IP drosseln (Global Constraint: 120 req/min pro Key).
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['api.auth', 'throttle:api'])
    ->group(function () {
        Route::get('me', [MeController::class, 'show'])->name('me');

        // Self-Service für eigene API-Keys. Feste Segmente (me/api-keys/...) vor
        // etwaigen Wildcard-Routen — keine Kollision mit `me`.
        Route::get('me/api-keys', [ApiKeyController::class, 'index'])->name('me.api-keys.index');
        Route::post('me/api-keys', [ApiKeyController::class, 'store'])->name('me.api-keys.store');
        Route::delete('me/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('me.api-keys.destroy');

        // Pakete-Verwaltung ist Betreiber-Sache: nur Orgs mit is_operator und
        // Rolle admin/maintainer dürfen anlegen/ändern — dieselben Gates wie die GUI.
        Route::middleware(['operator', 'role:admin,maintainer'])->group(function () {
            Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
            Route::post('packages', [PackageController::class, 'store'])->name('packages.store');
            Route::get('packages/{package}', [PackageController::class, 'show'])->name('packages.show');
            Route::post('packages/{package}/resync', [PackageController::class, 'resync'])->name('packages.resync');
            Route::delete('packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');

            // Registries (Gruppen) — dieselben Form Requests wie die Admin-GUI, daher
            // identisches Validierungsverhalten (inkl. JSON-Fehler bei Accept: application/json).
            Route::get('groups', [GroupController::class, 'index'])->name('groups.index');
            Route::post('groups', [GroupController::class, 'store'])->name('groups.store');
            Route::get('groups/{group}', [GroupController::class, 'show'])->name('groups.show');
            Route::put('groups/{group}', [GroupController::class, 'update'])->name('groups.update');
            Route::delete('groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');

            // Unterressourcen einer Registry: Domains, Upstreams, Paket-Zuordnung.
            // Wiederverwendet dieselben Form Requests wie der Admin-Flow.
            Route::get('groups/{group}/domains', [GroupDomainController::class, 'index'])->name('groups.domains.index');
            Route::post('groups/{group}/domains', [GroupDomainController::class, 'store'])->name('groups.domains.store');
            Route::delete('groups/{group}/domains/{domain}', [GroupDomainController::class, 'destroy'])->name('groups.domains.destroy');

            Route::get('groups/{group}/upstreams', [GroupUpstreamController::class, 'index'])->name('groups.upstreams.index');
            Route::post('groups/{group}/upstreams', [GroupUpstreamController::class, 'store'])->name('groups.upstreams.store');
            Route::delete('groups/{group}/upstreams/{upstream}', [GroupUpstreamController::class, 'destroy'])->name('groups.upstreams.destroy');

            Route::get('groups/{group}/packages', [GroupPackageController::class, 'index'])->name('groups.packages.index');
            Route::put('groups/{group}/packages', [GroupPackageController::class, 'update'])->name('groups.packages.update');

            // Registry-Tokens (kfx_-Pull/Publish-Tokens für Composer/npm), nicht zu
            // verwechseln mit den persönlichen API-Keys (kfxapi_) aus me/api-keys.
            Route::get('registry-tokens', [RegistryTokenController::class, 'index'])->name('registry-tokens.index');
            Route::post('registry-tokens', [RegistryTokenController::class, 'store'])->name('registry-tokens.store');
            Route::delete('registry-tokens/{registryToken}', [RegistryTokenController::class, 'destroy'])->name('registry-tokens.destroy');
        });
    });

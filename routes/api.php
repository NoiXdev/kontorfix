<?php

use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\GroupDomainController;
use App\Http\Controllers\Api\V1\GroupPackageController;
use App\Http\Controllers\Api\V1\GroupUpstreamController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PackageController;
use App\Http\Controllers\Api\V1\RegistryTokenController;
use App\Http\Controllers\Api\V1\RobotApiKeyController;
use App\Http\Controllers\Api\V1\StatusController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

// All management endpoints are stateless (bearer key), versioned under /api/v1.
// Two-tier rate limit: a coarse IP limit (throttle:api, 240/min) BEFORE the
// auth middleware protects the unauthenticated surface — invalid/missing bearer
// tokens would otherwise trigger an unthrottled DB lookup per request (unauth DoS).
// Laravel's middlewarePriority sorts throttle before api.auth anyway, which is
// exactly what's wanted here. The finer per-key limit (120/min, global constraint) remains
// additionally in the api.auth middleware itself, right after key resolution.
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['throttle:api', 'api.auth'])
    ->group(function () {
        Route::get('me', [MeController::class, 'show'])->name('me');

        // Self-service for one's own API keys. Fixed segments (me/api-keys/...) before
        // any wildcard routes — no collision with `me`.
        Route::get('me/api-keys', [ApiKeyController::class, 'index'])->name('me.api-keys.index');
        Route::post('me/api-keys', [ApiKeyController::class, 'store'])->name('me.api-keys.store');
        Route::delete('me/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('me.api-keys.destroy');

        // The v1 management surface operates instance-wide and its controllers are not
        // organization-scoped, so it is reserved for the global super-admin — the same
        // posture as the instance-wide admin console. Per-organization admins manage
        // their registries through the scoped web console, not this API.
        Route::middleware('super')->group(function () {
            Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
            Route::post('packages', [PackageController::class, 'store'])->name('packages.store');
            Route::get('packages/{package}', [PackageController::class, 'show'])->name('packages.show');
            Route::post('packages/{package}/resync', [PackageController::class, 'resync'])->name('packages.resync');
            Route::delete('packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');

            // Registries (groups) — the same form requests as the admin GUI, hence
            // identical validation behavior (including JSON errors for Accept: application/json).
            Route::get('groups', [GroupController::class, 'index'])->name('groups.index');
            Route::post('groups', [GroupController::class, 'store'])->name('groups.store');
            Route::get('groups/{group}', [GroupController::class, 'show'])->name('groups.show');
            Route::put('groups/{group}', [GroupController::class, 'update'])->name('groups.update');
            Route::delete('groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');

            // Sub-resources of a registry: domains, upstreams, package assignment.
            // Reuses the same form requests as the admin flow.
            Route::get('groups/{group}/domains', [GroupDomainController::class, 'index'])->name('groups.domains.index');
            Route::post('groups/{group}/domains', [GroupDomainController::class, 'store'])->name('groups.domains.store');
            Route::delete('groups/{group}/domains/{domain}', [GroupDomainController::class, 'destroy'])->name('groups.domains.destroy');

            Route::get('groups/{group}/upstreams', [GroupUpstreamController::class, 'index'])->name('groups.upstreams.index');
            Route::post('groups/{group}/upstreams', [GroupUpstreamController::class, 'store'])->name('groups.upstreams.store');
            Route::delete('groups/{group}/upstreams/{upstream}', [GroupUpstreamController::class, 'destroy'])->name('groups.upstreams.destroy');

            Route::get('groups/{group}/packages', [GroupPackageController::class, 'index'])->name('groups.packages.index');
            Route::put('groups/{group}/packages', [GroupPackageController::class, 'update'])->name('groups.packages.update');

            // Registry tokens (kfx_ pull/publish tokens for Composer/npm), not to be
            // confused with the personal API keys (kfxapi_) from me/api-keys.
            Route::get('registry-tokens', [RegistryTokenController::class, 'index'])->name('registry-tokens.index');
            Route::post('registry-tokens', [RegistryTokenController::class, 'store'])->name('registry-tokens.store');
            Route::delete('registry-tokens/{registryToken}', [RegistryTokenController::class, 'destroy'])->name('registry-tokens.destroy');

            // Outgoing webhooks (the same form requests as the admin GUI) and a
            // lean status endpoint for external monitoring clients.
            Route::get('webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
            Route::post('webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
            Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');

            Route::get('status', [StatusController::class, 'show'])->name('status');
        });

        // Organization, user and robot management — likewise super-admin only.
        Route::middleware('super')->group(function () {
            Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
            Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
            Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
            Route::delete('organizations/{organization}', [OrganizationController::class, 'destroy'])->name('organizations.destroy');

            // User & robot management as well as issuing API keys for
            // arbitrary accounts is likewise strictly operator-admin.
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
            Route::post('users/{user}/api-keys', [RobotApiKeyController::class, 'store'])->name('users.api-keys.store');
        });
    });

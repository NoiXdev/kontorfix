<?php

use App\Http\Controllers\Registry\ComposerController;
use App\Http\Controllers\Registry\NpmController;
use App\Http\Controllers\Registry\ProxyDownloadController;
use Illuminate\Support\Facades\Route;

// Registry-Endpunkte werden EINMAL definiert und unter zwei Zugriffspfaden registriert:
// per Slug-Präfix (/r/{groupSlug}/...) und an der Host-Wurzel für Custom-Domains.
// Die Gruppen-Auflösung übernimmt ausschließlich `registry.context` (siehe
// ResolveRegistryContext) — Controller lesen die Gruppe aus den Request-Attributen.
$registryEndpoints = function () {
    // Composer
    Route::get('/packages.json', [ComposerController::class, 'root']);
    Route::get('/p2/{vendor}/{name}.json', [ComposerController::class, 'metadata'])
        ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.~-]+']);
    Route::get('/dists/{vendor}/{name}/{version}.zip', [ComposerController::class, 'dist'])
        ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.-]+', 'version' => '[^/]+']);

    // Proxy-Downloads: {upstream} ist eine UUID, wird bewusst NICHT per Route-Model-Binding
    // aufgelöst, sondern manuell im Controller — so bleibt die Gruppen-Zugehörigkeitsprüfung
    // explizit (kein Token darf über einen fremden Upstream Downloads anstoßen).
    Route::get('/proxy/composer/{upstream}/{vendor}/{name}/{version}', [ProxyDownloadController::class, 'composer'])
        ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.-]+', 'version' => '[^/]+']);
    Route::get('/proxy/npm/{upstream}/{scope}/{package}/-/{file}', [ProxyDownloadController::class, 'npmScoped'])
        ->where(['scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']);
    Route::get('/proxy/npm/{upstream}/{package}/-/{file}', [ProxyDownloadController::class, 'npm'])
        ->where(['package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']);

    // npm — nach den Composer-Routen (First-Match schützt packages.json/p2/dists).
    // Der `/-/`-Tarball-Pfad kollidiert mit keiner Composer-Route, daher brauchen die
    // Tarball-Routen kein packages.json-Lookahead — nur der bare packument-Catch-all unten.
    Route::get('/{scope}/{package}/-/{file}', [NpmController::class, 'tarballScoped'])
        ->where(['scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']);
    Route::get('/{package}/-/{file}', [NpmController::class, 'tarball'])
        ->where(['package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']);
    Route::get('/{scope}/{package}', [NpmController::class, 'packumentScoped'])
        ->where(['scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+']);
    Route::get('/{package}', [NpmController::class, 'packument'])
        ->where(['package' => '(?!packages\.json$)[a-z0-9._-]+']);

    Route::put('/{scope}/{package}', [NpmController::class, 'publishScoped'])
        ->where(['scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+']);
    Route::put('/{package}', [NpmController::class, 'publish'])
        ->where(['package' => '[a-z0-9._-]+']);
};

// Slug-Zugriff: {groupSlug} als schlichter Parameter, von der Middleware aufgelöst.
Route::prefix('/r/{groupSlug}')
    ->where(['groupSlug' => '[a-z0-9-]+'])
    ->middleware(['registry.context', 'registry.auth'])
    ->group($registryEndpoints);

// Domain-Zugriff: Root-Ebene. registry.context 404t unbekannte Hosts, daher überschatten
// diese Routen die Haupt-App nicht (web-Routen sind zuvor registriert -> First-Match).
Route::middleware(['registry.context', 'registry.auth'])->group($registryEndpoints);

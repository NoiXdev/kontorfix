<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Facades\Storage;

it('completes the full npm flow: publish -> packument -> tarball', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);
    $bytes = 'hello-tarball';

    // 1. Wie `npm publish`: PUT mit versions + _attachments.
    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', $bytes))
        ->assertOk();

    // 2. Wie `npm install`: packument holen, dist.tarball auslesen.
    $doc = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/leftpad')->assertOk()->json();
    expect($doc['dist-tags']['latest'])->toBe('1.0.0')
        ->and($doc['versions']['1.0.0']['dist']['integrity'])->toBe('sha512-'.base64_encode(hash('sha512', $bytes, true)));

    // 3. Tarball über die exakte URL aus dem packument laden.
    $tarballUrl = $doc['versions']['1.0.0']['dist']['tarball'];
    $path = parse_url($tarballUrl, PHP_URL_PATH);
    $this->withHeaders(tokenHeaderFor($group))->get($path)
        ->assertOk()
        ->assertHeader('content-type', 'application/octet-stream');
});

it('completes the same flow for a scoped package', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => '@noixdev/ui-kit']);
    $group->packages()->attach($pkg);

    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/@noixdev/ui-kit', publishBody('@noixdev/ui-kit', '2.1.0', 'ui-kit-2.1.0.tgz', 'scoped'))
        ->assertOk();

    $doc = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/@noixdev/ui-kit')->assertOk()->json();
    $path = parse_url($doc['versions']['2.1.0']['dist']['tarball'], PHP_URL_PATH);
    $this->withHeaders(tokenHeaderFor($group))->get($path)->assertOk();
});

it('serves the npm endpoints a 401 across the board without a token', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    $this->getJson('/r/kadenz/leftpad')->assertUnauthorized();
    $this->get('/r/kadenz/leftpad/-/leftpad-1.0.0.tgz')->assertUnauthorized();
    $this->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', 'x'))->assertUnauthorized();
});

/*
 * Manueller Smoke-Test (2026-07-08), gegen den laufenden DDEV-Server mit echten
 * npm-Clients verifiziert:
 *
 *   .npmrc:
 *     @noixdev:registry=https://kontorfix.ddev.site/r/npmsmoke/
 *     //kontorfix.ddev.site/r/npmsmoke/:_authToken=kfx_...   (publish-Token)
 *
 *   npm publish  ->  + @noixdev/smoke@1.0.0
 *   npm install @noixdev/smoke (mit read-Token)  ->  added 1 package
 *     -> node_modules/@noixdev/smoke/{package.json,index.js} korrekt entpackt.
 *
 * Wichtiger Integrationsbefund dabei: npm nennt das _attachments bei scoped Paketen
 * "@scope/name-version.tgz" (mit @ und /). Der Storage-Dateiname wird deshalb
 * serverseitig aus (unscoped) Name + Version abgeleitet, nicht aus npms Key.
 */

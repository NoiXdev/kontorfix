<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Facades\Storage;

it('completes the full npm flow: publish -> packument -> tarball', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);
    $bytes = 'hello-tarball';

    // 1. Like `npm publish`: PUT with versions + _attachments.
    $this->withHeaders(publishHeaderFor($group))
        ->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', $bytes))
        ->assertOk();

    // 2. Like `npm install`: fetch the packument, read dist.tarball.
    $doc = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/leftpad')->assertOk()->json();
    expect($doc['dist-tags']['latest'])->toBe('1.0.0')
        ->and($doc['versions']['1.0.0']['dist']['integrity'])->toBe('sha512-'.base64_encode(hash('sha512', $bytes, true)));

    // 3. Load the tarball via the exact URL from the packument.
    $tarballUrl = $doc['versions']['1.0.0']['dist']['tarball'];
    $path = parse_url($tarballUrl, PHP_URL_PATH);
    $this->withHeaders(tokenHeaderFor($group))->get($path)
        ->assertOk()
        ->assertHeader('content-type', 'application/octet-stream');
});

it('completes the same flow for a scoped package', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => '@noixdev/ui-kit']);
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
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    $group->packages()->attach($pkg);

    $this->getJson('/r/kadenz/leftpad')->assertUnauthorized();
    $this->get('/r/kadenz/leftpad/-/leftpad-1.0.0.tgz')->assertUnauthorized();
    $this->putJson('/r/kadenz/leftpad', publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', 'x'))->assertUnauthorized();
});

/*
 * Manual smoke test (2026-07-08), verified against the running DDEV server with real
 * npm clients:
 *
 *   .npmrc:
 *     @noixdev:registry=https://kontorfix.ddev.site/r/npmsmoke/
 *     //kontorfix.ddev.site/r/npmsmoke/:_authToken=kfx_...   (publish token)
 *
 *   npm publish  ->  + @noixdev/smoke@1.0.0
 *   npm install @noixdev/smoke (with a read token)  ->  added 1 package
 *     -> node_modules/@noixdev/smoke/{package.json,index.js} extracted correctly.
 *
 * Important integration finding along the way: npm names the _attachments entry for
 * scoped packages "@scope/name-version.tgz" (with @ and /). The storage filename is
 * therefore derived server-side from the (unscoped) name + version, not from npm's key.
 */

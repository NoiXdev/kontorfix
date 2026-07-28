<?php

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Jobs\SyncPackage;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Upstream;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FixtureRepo;

function domainHeaders(Group $group, string $host): array
{
    return array_merge(['Host' => $host], tokenHeaderFor($group));
}

it('runs the full composer flow at the domain root: root -> p2 -> dist', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    $pkg = Package::factory()->create(['type' => PackageType::Composer, 'name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group->packages()->attach($pkg);
    $host = 'packages.kadenz.test';
    $headers = domainHeaders($group, $host);

    $root = $this->withHeaders($headers)->getJson("http://{$host}/packages.json")->assertOk()->json();
    expect($root['metadata-url'])->toBe('/p2/%package%.json');

    $metaUrl = str_replace('%package%', 'acme/demo', $root['metadata-url']);
    $meta = $this->withHeaders($headers)->getJson("http://{$host}{$metaUrl}")->assertOk()->json();
    $version = MetadataMinifier::expand($meta['packages']['acme/demo'])[0];

    // Dist URL is domain-root (no /r/{slug}).
    expect($version['dist']['url'])->toStartWith("http://{$host}/dists/");
    $distPath = parse_url($version['dist']['url'], PHP_URL_PATH);
    $this->withHeaders($headers)->get("http://{$host}{$distPath}")->assertOk()->assertHeader('content-type', 'application/zip');
});

it('runs the full npm flow at the domain root: packument -> tarball', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'npm.kadenz.test']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad', 'dist_tags' => ['latest' => '1.0.0']]);
    $group->packages()->attach($pkg);
    $host = 'npm.kadenz.test';
    $headers = domainHeaders($group, $host);

    // publish via the domain root (bearer token with publish ability)
    $publishHeaders = array_merge(['Host' => $host], publishHeaderFor($group));
    $this->withHeaders($publishHeaders)
        ->putJson("http://{$host}/leftpad", publishBody('leftpad', '1.0.0', 'leftpad-1.0.0.tgz', 'bytes'))
        ->assertOk();

    $doc = $this->withHeaders($headers)->getJson("http://{$host}/leftpad")->assertOk()->json();
    expect($doc['versions']['1.0.0']['dist']['tarball'])->toStartWith("http://{$host}/leftpad/-/");
    $tarballPath = parse_url($doc['versions']['1.0.0']['dist']['tarball'], PHP_URL_PATH);
    $this->withHeaders($headers)->get("http://{$host}{$tarballPath}")->assertOk();
});

it('proxies an upstream at the domain root with domain-root proxy urls', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test', 'policy' => UpstreamPolicy::Proxy]);
    Http::fake([
        '*/p2/symfony/console.json' => Http::response(['packages' => ['symfony/console' => MetadataMinifier::minify([
            ['name' => 'symfony/console', 'version' => 'v6.0.0', 'version_normalized' => '6.0.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://cdn.test/s.zip', 'reference' => 'r']],
        ])]], 200),
        'cdn.test/*' => Http::response('zip-bytes', 200),
    ]);
    $host = 'packages.kadenz.test';
    $headers = domainHeaders($group, $host);

    $meta = $this->withHeaders($headers)->getJson("http://{$host}/p2/symfony/console.json")->assertOk()->json();
    $version = MetadataMinifier::expand($meta['packages']['symfony/console'])[0];
    expect($version['dist']['url'])->toStartWith("http://{$host}/proxy/composer/{$up->id}/symfony/console/");

    $proxyPath = parse_url($version['dist']['url'], PHP_URL_PATH);
    $this->withHeaders($headers)->get("http://{$host}{$proxyPath}")->assertOk();
});

/*
 * Manual multi-domain smoke test (2026-07-08), verified against the running DDEV server
 * with real clients. A custom domain (packages-kadenz.ddev.site, as an additional
 * DDEV hostname) pointed via the domains table to the group "proxytest"
 * (with packagist and npmjs proxy upstreams):
 *
 *   GET https://packages-kadenz.ddev.site/packages.json
 *     -> {"metadata-url":"/p2/%package%.json"}   (root-relative, NO /r/slug)
 *
 *   composer.json: repositories=[{"type":"composer","url":"https://packages-kadenz.ddev.site"}]
 *   composer require psr/container  ->  Installing psr/container (2.0.2)
 *
 *   .npmrc: registry=https://packages-kadenz.ddev.site/
 *   npm install is-number  ->  added 1 package (is-number@7.0.0)
 *
 * The entire registry (private packages, proxy, downloads) ran under the domain root.
 */

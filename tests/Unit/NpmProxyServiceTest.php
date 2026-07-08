<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Upstream;
use App\Services\Upstream\NpmProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('rewrites tarball urls and caches the raw packument', function () {
    $group = Group::factory()->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Npm, 'url' => 'https://reg.test']);
    Http::fake(['*/left-pad' => Http::response([
        'name' => 'left-pad', 'dist-tags' => ['latest' => '1.0.0'],
        'versions' => ['1.0.0' => ['name' => 'left-pad', 'version' => '1.0.0', 'dist' => ['tarball' => 'https://reg.test/left-pad/-/left-pad-1.0.0.tgz', 'shasum' => 'x']]],
    ], 200)]);

    $doc = app(NpmProxyService::class)->packument($group, $up, 'left-pad', 'https://registry.test/r/kadenz');

    expect($doc['versions']['1.0.0']['dist']['tarball'])
        ->toBe('https://registry.test/r/kadenz/proxy/npm/'.$up->id.'/left-pad/-/left-pad-1.0.0.tgz')
        ->and($up->metadataCache()->where('package_name', 'left-pad')->exists())->toBeTrue();

    Http::fake();
    app(NpmProxyService::class)->packument($group, $up, 'left-pad', 'https://registry.test/r/kadenz');
    Http::assertNothingSent();
});

<?php

use App\Models\Domain;
use App\Models\Group;
use App\Services\Registry\RegistryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the app url with slug path when the group has no domain', function () {
    config(['app.url' => 'https://reg.example.test']);
    $group = Group::factory()->create(['slug' => 'acme']);

    $url = app(RegistryUrl::class);
    expect($url->base($group))->toBe('https://reg.example.test/r/acme');
    expect($url->host($group))->toBe('reg.example.test');
    expect($url->pathPrefix($group))->toBe('/r/acme');
});

it('uses the custom domain at its root when the group has one', function () {
    $group = Group::factory()->create(['slug' => 'acme']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.acme.test']);

    $url = app(RegistryUrl::class);
    expect($url->base($group->fresh()))->toBe('https://packages.acme.test');
    expect($url->host($group->fresh()))->toBe('packages.acme.test');
    expect($url->pathPrefix($group->fresh()))->toBe('');
});

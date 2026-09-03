<?php

use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Services\Registry\RegistryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the app url with slug path when the group has no domain', function () {
    config(['app.url' => 'https://reg.example.test']);
    // The organization scopes the slug and therefore appears in the address.
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'kunde']))->create(['slug' => 'acme']);

    $url = app(RegistryUrl::class);
    expect($url->base($group))->toBe('https://reg.example.test/r/kunde/acme');
    expect($url->host($group))->toBe('reg.example.test');
    expect($url->pathPrefix($group))->toBe('/r/kunde/acme');
});

it('uses the custom domain at its root when the group has one', function () {
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'kunde']))->create(['slug' => 'acme']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.acme.test']);

    $url = app(RegistryUrl::class);
    expect($url->base($group->fresh()))->toBe('https://packages.acme.test');
    expect($url->host($group->fresh()))->toBe('packages.acme.test');
    expect($url->pathPrefix($group->fresh()))->toBe('');
});

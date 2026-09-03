<?php

use App\Models\Group;
use App\Services\Registry\RegistryUrl;

it('states the registry path form in exactly one place', function () {
    $group = Group::factory()->create(['slug' => 'tools']);

    expect(app(RegistryUrl::class)->path($group))->toBe('/r/tools');
});

it('keeps pathPrefix empty for a registry served on its own domain', function () {
    $group = Group::factory()->create(['slug' => 'tools']);
    $group->domains()->create(['hostname' => 'packages.example.com']);

    expect(app(RegistryUrl::class)->pathPrefix($group->fresh()))->toBe('')
        ->and(app(RegistryUrl::class)->path($group->fresh()))->toBe('/r/tools');
});

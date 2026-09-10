<?php

use App\Models\Group;
use App\Models\Organization;
use App\Services\Registry\RegistryUrl;

it('states the registry path form in exactly one place', function () {
    // The organization scopes the slug, so it is part of the address — a slug alone no
    // longer identifies one registry (see OrgScopedSlugTest).
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'kunde']))->create(['slug' => 'tools']);

    expect(app(RegistryUrl::class)->path($group))->toBe('/r/kunde/tools');
});

it('keeps pathPrefix empty for a registry served on its own domain', function () {
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'kunde']))->create(['slug' => 'tools']);
    $group->domains()->create(['hostname' => 'packages.example.com']);

    expect(app(RegistryUrl::class)->pathPrefix($group->fresh()))->toBe('')
        ->and(app(RegistryUrl::class)->path($group->fresh()))->toBe('/r/kunde/tools');
});

it('states the org-endpoint path form as a bare path, the same shape pathFor() uses', function () {
    // Absolute path only — no scheme, no host — exactly like pathFor()'s '/r/{org}/{registry}'.
    // SetupSnippetBuilder::forOrganization() is the consumer this exists for.
    $organization = Organization::factory()->create(['slug' => 'kunde']);

    expect(app(RegistryUrl::class)->orgPath($organization))->toBe('/o/kunde');
});

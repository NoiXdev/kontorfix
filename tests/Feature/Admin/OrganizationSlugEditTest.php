<?php

use App\Models\Group;
use App\Models\Organization;

it('changes an organization slug and with it every registry url it owns', function () {
    $org = Organization::factory()->create(['slug' => 'old-org']);
    $group = Group::factory()->for($org)->create(['slug' => 'packages', 'public' => true]);

    $this->actingAs(superAdmin())->put(route('admin.organizations.update', $org), [
        'name' => $org->name,
        'slug' => 'new-org',
        'notification_cadence' => cadenceOf($org),
    ])->assertRedirect();

    $this->get('/r/new-org/packages/packages.json')->assertOk();
    $this->get('/r/old-org/packages/packages.json')->assertNotFound();
    expect($group->fresh()->slug)->toBe('packages');
});

it('refuses an organization slug that is already taken', function () {
    Organization::factory()->create(['slug' => 'taken']);
    $org = Organization::factory()->create(['slug' => 'mine']);

    $this->actingAs(superAdmin())->put(route('admin.organizations.update', $org), [
        'name' => $org->name, 'slug' => 'taken', 'notification_cadence' => cadenceOf($org),
    ])->assertSessionHasErrors('slug');
});

/**
 * The other half of App\Rules\UnclaimedSlug's seam. StoreOrganizationRequest guards the
 * create path (byRegistry()); without the same rule here, an organization slug could be
 * edited back onto a registry slug it already collides with — and the legacy /r/{slug}
 * redirect would then start resolving to the wrong registry, silently, exactly as the
 * matching test on the registry-slug update path (GroupSlugEditTest.php) proves for the
 * other direction.
 */
it('refuses an organization slug a registry already answers to', function () {
    Group::factory()->create(['slug' => 'acme']);

    $org = Organization::factory()->create(['slug' => 'mine']);

    $this->actingAs(superAdmin())->put(route('admin.organizations.update', $org), [
        'name' => $org->name, 'slug' => 'acme', 'notification_cadence' => cadenceOf($org),
    ])->assertSessionHasErrors('slug');

    expect($org->fresh()->slug)->toBe('mine');
});

/**
 * `notification_cadence` has a DB default ('hourly'), not a factory one — a freshly created
 * model instance does not carry it until reloaded (the UUID-model DB-default gotcha this
 * codebase already knows about), so a PUT that echoes `$org->notification_cadence` straight
 * back would submit null and get refused by the request's own `required` rule.
 */
function cadenceOf(Organization $org): string
{
    return $org->refresh()->notification_cadence;
}

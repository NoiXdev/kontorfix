<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

/**
 * The organization half of `oci_auto_create_repositories` in the console: three states, and
 * a round trip that proves all three survive the form.
 *
 * A sibling of RegistryTypeManagementTest rather than an extension of it: that file is about
 * the registry-type ceiling and its protocol effects, this one about a different setting
 * that merely shares the ceiling/narrowing shape.
 */
beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
    $this->customer = Organization::factory()->create(['slug' => 'kunde', 'is_operator' => false]);
});

/**
 * The organization update endpoint takes the whole settings form, so every case sends the
 * other required fields unchanged and varies only the one under test.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function organizationPayload(Organization $org, array $overrides = []): array
{
    return [
        'name' => $org->name,
        'slug' => $org->slug,
        'notification_cadence' => $org->notification_cadence ?? 'daily',
        'portal_enabled' => $org->portal_enabled,
        ...$overrides,
    ];
}

it('inherits by default and says so in the page payload', function () {
    expect($this->customer->oci_auto_create_repositories)->toBeNull();

    $this->actingAs($this->admin)->get("/admin/organizations/{$this->customer->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('organization.oci_auto_create_repositories', null)
            // The ceiling and what it currently resolves to, so the page can say what
            // "Erben" means instead of leaving the operator to look it up.
            ->where('ociAutoCreate.global', false)
            ->where('ociAutoCreate.effective', false));
});

it('stores an explicit on and an explicit off', function () {
    $this->actingAs($this->admin)
        ->put("/admin/organizations/{$this->customer->id}", organizationPayload($this->customer, ['oci_auto_create_repositories' => true]))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($this->customer->fresh()->oci_auto_create_repositories)->toBeTrue();

    $this->actingAs($this->admin)
        ->put("/admin/organizations/{$this->customer->id}", organizationPayload($this->customer, ['oci_auto_create_repositories' => false]))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($this->customer->fresh()->oci_auto_create_repositories)->toBeFalse();
});

it('accepts null and hands the organization back to the instance setting', function () {
    // `nullable` is the whole reason the inherit state is reachable from the console. A
    // two-state control could not express it, and would have written false here.
    $this->customer->update(['oci_auto_create_repositories' => false]);

    $this->actingAs($this->admin)
        ->put("/admin/organizations/{$this->customer->id}", organizationPayload($this->customer, ['oci_auto_create_repositories' => null]))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($this->customer->fresh()->oci_auto_create_repositories)->toBeNull();
});

it('leaves the setting alone for a payload that never mentions it', function () {
    // `sometimes`: "the form did not send the field" and "the form sent null" are different
    // intentions, and only the second one means inherit.
    $this->customer->update(['oci_auto_create_repositories' => true]);

    $this->actingAs($this->admin)
        ->put("/admin/organizations/{$this->customer->id}", organizationPayload($this->customer))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($this->customer->fresh()->oci_auto_create_repositories)->toBeTrue();
});

it('rejects a non-boolean', function () {
    $this->actingAs($this->admin)
        ->put("/admin/organizations/{$this->customer->id}", organizationPayload($this->customer, ['oci_auto_create_repositories' => 'vielleicht']))
        ->assertSessionHasErrors('oci_auto_create_repositories');
});

<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciTag;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RetentionPolicy;
use App\Models\SystemSetting;
use App\Models\User;

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
    $this->org = Organization::factory()->create();
    $this->member = User::factory()->for($this->org)->create(['role' => UserRole::Member]);
    $this->group = Group::factory()->for($this->org)->create(['slug' => 'acme']);
    $this->pkg = Package::factory()->inOrgOf($this->group)->docker()->create(['name' => 'acme/app']);
    $this->group->packages()->attach($this->pkg);

    foreach (['neu' => '2026-09-07 00:00:00', 'alt' => '2020-01-01 00:00:00'] as $name => $pushedAt) {
        OciTag::factory()->create([
            'package_id' => $this->pkg->id,
            'name' => $name,
            'manifest_id' => OciManifest::factory()->for($this->pkg)->create()->id,
            'pushed_at' => $pushedAt,
        ]);
    }

    $this->pageUrl = "/c/{$this->org->slug}/registries/{$this->group->id}/packages/{$this->pkg->id}";
});

it('shows the resolved policy and what the next run would remove', function () {
    $policy = RetentionPolicy::factory()->create(['name' => 'Standard', 'rules' => [['type' => 'keep_last', 'count' => 1]]]);
    SystemSetting::current()->update(['retention_policy_id' => $policy->id]);

    $this->actingAs($this->member)->get($this->pageUrl)
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('retention.policy_name', 'Standard')
            // The rules in the SAME words the operator's dry run uses —
            // RetentionRule::describe(), one string, so the two surfaces cannot drift.
            ->where('retention.rules.0', 'Letzte 1 behalten')
            ->where('retention.removals.0.name', 'alt')
            ->where('retention.removals.0.pushed_at', '2020-01-01 00:00:00'));
});

it('says nothing is removed when no policy resolves', function () {
    // An absent section would be indistinguishable from one that failed to load, so the
    // prop is present with policy_name null and the page renders the explicit sentence.
    $this->actingAs($this->member)->get($this->pageUrl)
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('retention.policy_name', null)
            ->where('retention.rules', [])
            ->where('retention.removals', []));
});

it('sends no retention prop content for a non-docker package', function () {
    $composer = Package::factory()->inOrgOf($this->group)->create(['type' => 'composer', 'name' => 'acme/widget']);
    $this->group->packages()->attach($composer);
    SystemSetting::current()->update([
        'retention_policy_id' => RetentionPolicy::factory()->create()->id,
    ]);

    $this->actingAs($this->member)
        ->get("/c/{$this->org->slug}/registries/{$this->group->id}/packages/{$composer->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('retention', null));
});

it('offers no way to change anything', function () {
    // No portal route exists for assigning or applying; the ADMIN routes refuse a portal
    // member outright. Both halves asserted: the refusal and that nothing changed.
    $policy = RetentionPolicy::factory()->create(['rules' => [['type' => 'keep_last', 'count' => 1]]]);
    SystemSetting::current()->update(['retention_policy_id' => $policy->id]);

    $this->actingAs($this->member)
        ->put(route('admin.packages.retention.update', $this->pkg), ['retention_policy_id' => null])
        ->assertForbidden();

    $this->actingAs($this->member)
        ->post(route('admin.packages.retention.apply', $this->pkg))
        ->assertForbidden();

    expect($this->pkg->fresh()->retention_policy_id)->toBeNull()
        ->and($this->pkg->ociTags()->count())->toBe(2);
});

it("never shows another organization's repository", function () {
    // The existing portal scoping, re-asserted on the surface that now carries retention
    // data. 404, not 403: /c/{org} resolves the CALLER's portal context, and a foreign
    // member asking under someone else's slug is told the address does not exist — the
    // page's answer must not depend on who is asking.
    $foreign = User::factory()->for(Organization::factory()->create())->create(['role' => UserRole::Member]);

    $this->actingAs($foreign)->get($this->pageUrl)->assertNotFound();
});

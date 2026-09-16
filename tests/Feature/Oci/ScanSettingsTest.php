<?php

use App\Enums\PackageType;
use App\Enums\ScanStatus;
use App\Enums\VulnerabilitySeverity;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciScanFinding;
use App\Models\OciScanReport;
use App\Models\Organization;
use App\Models\Package;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['kontorfix.scanner.enabled' => true]);

    $this->org = Organization::factory()->create();
    $this->group = Group::factory()->for($this->org)->create();
    $this->package = Package::factory()->for($this->org)->create(['type' => PackageType::Docker, 'name' => 'meinapp']);
    $this->group->packages()->attach($this->package->id);
    $this->manifest = OciManifest::factory()->for($this->package)->create();
});

function blockingFindingOn(OciManifest $manifest, int $daysKnown): void
{
    $report = OciScanReport::factory()->for($manifest, 'manifest')->create(['status' => ScanStatus::Ok]);
    OciScanFinding::factory()->for($report, 'report')
        ->severity(VulnerabilitySeverity::Critical)->firstSeenDaysAgo($daysKnown)->create();
}

it('stores a threshold and a grace period', function () {
    $this->actingAs(superAdmin())
        ->put(route('admin.groups.scan-blocking', $this->group), [
            'scan_block_severity' => 'high',
            'scan_block_grace_days' => 14,
        ])->assertRedirect();

    expect($this->group->fresh()->scan_block_severity)->toBe(VulnerabilitySeverity::High)
        ->and($this->group->fresh()->scan_block_grace_days)->toBe(14);
});

it('switches blocking off again', function () {
    $this->group->update(['scan_block_severity' => VulnerabilitySeverity::High]);

    $this->actingAs(superAdmin())
        ->put(route('admin.groups.scan-blocking', $this->group), [
            'scan_block_severity' => null,
            'scan_block_grace_days' => 7,
        ])->assertRedirect();

    expect($this->group->fresh()->scan_block_severity)->toBeNull();
});

it('refuses a severity that is not one of the five', function () {
    $this->actingAs(superAdmin())
        ->put(route('admin.groups.scan-blocking', $this->group), [
            'scan_block_severity' => 'catastrophic',
            'scan_block_grace_days' => 7,
        ])->assertSessionHasErrors('scan_block_severity');
});

it('refuses a negative grace period', function () {
    $this->actingAs(superAdmin())
        ->put(route('admin.groups.scan-blocking', $this->group), [
            'scan_block_severity' => 'high',
            'scan_block_grace_days' => -1,
        ])->assertSessionHasErrors('scan_block_grace_days');
});

it('refuses a caller who does not administer the registry', function () {
    $this->actingAs(adminOf(Organization::factory()->create()))
        ->put(route('admin.groups.scan-blocking', $this->group), [
            'scan_block_severity' => 'high',
            'scan_block_grace_days' => 7,
        ])->assertForbidden();
});

it('previews the proposed setting without saving it', function () {
    blockingFindingOn($this->manifest, 30);

    $this->actingAs(superAdmin())
        ->postJson(route('admin.groups.scan-preview', $this->group), [
            'scan_block_severity' => 'high',
            'scan_block_grace_days' => 7,
        ])
        ->assertOk()
        ->assertJsonPath('blocking_now', 1)
        ->assertJsonPath('artifacts.0.package', 'meinapp');

    // The preview is read-only. An operator who is only looking must not have changed
    // anything by looking.
    expect($this->group->fresh()->scan_block_severity)->toBeNull();
});

it('previews through the same code that later refuses for real', function () {
    // The argument for having a preview at all is that it tells the truth. A second
    // statement of the rule could disagree with the registry, and the operator acts on the
    // preview — so this asserts the two agree on a case that separates them: a finding
    // inside its grace is "later", never "now".
    blockingFindingOn($this->manifest, 3);

    $this->actingAs(superAdmin())
        ->postJson(route('admin.groups.scan-preview', $this->group), [
            'scan_block_severity' => 'high',
            'scan_block_grace_days' => 7,
        ])
        ->assertOk()
        ->assertJsonPath('blocking_now', 0)
        ->assertJsonPath('blocking_later', 1);
});

it('refuses a preview from someone who does not administer the registry', function () {
    $this->actingAs(adminOf(Organization::factory()->create()))
        ->postJson(route('admin.groups.scan-preview', $this->group), [
            'scan_block_severity' => 'high',
            'scan_block_grace_days' => 7,
        ])->assertForbidden();
});

it('carries the registry page the current blocking settings', function () {
    $this->group->update(['scan_block_severity' => VulnerabilitySeverity::Medium, 'scan_block_grace_days' => 3]);

    $this->actingAs(superAdmin())
        ->get(route('admin.groups.show', $this->group))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/groups/Show')
            ->where('scan_blocking.severity', 'medium')
            ->where('scan_blocking.grace_days', 3)
            ->where('scan_blocking.enabled', true));
});

it('tells the registry page that scanning is switched off instance-wide', function () {
    // The section renders an explanation instead of a control: a threshold that nothing
    // will ever evaluate is worse than no threshold, because the operator believes they
    // are protected.
    config(['kontorfix.scanner.enabled' => false]);

    $this->actingAs(superAdmin())
        ->get(route('admin.groups.show', $this->group))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('scan_blocking.enabled', false));
});

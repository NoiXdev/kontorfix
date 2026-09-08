<?php

use App\Jobs\SweepOciStorage;
use App\Models\OciBlob;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('artifacts');
    $this->travelTo('2026-09-08 12:00:00');
});

function sweeperPageBlob(Package $package, string $digest, int $ageHours): OciBlob
{
    $path = 'docker/blobs/'.$package->organization_id.'/'.$digest;
    Storage::disk('artifacts')->put($path, 'x');

    return OciBlob::factory()->create([
        'organization_id' => $package->organization_id,
        'digest' => $digest,
        'size' => 1,
        'path' => $path,
        'created_at' => now()->subHours($ageHours),
    ]);
}

it('shows how many blobs the grace period is holding', function () {
    $package = Package::factory()->docker()->create();
    sweeperPageBlob($package, 'sha256:stale', 48);
    sweeperPageBlob($package, 'sha256:fresh', 2);

    $this->actingAs(superAdmin())
        ->get(route('admin.oci.sweeper'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/oci/Sweeper')
            // The number whose absence is only noticed after a push has broken.
            ->where('pending.blobs_held_by_grace', 1)
            ->where('pending.blobs_remaining', 1)
            ->where('grace_hours', 24)
            ->where('last_run', null));
});

it('renders the page without removing anything', function () {
    $package = Package::factory()->docker()->create();
    sweeperPageBlob($package, 'sha256:stale', 48);
    sweeperPageBlob($package, 'sha256:fresh', 2);

    $this->actingAs(superAdmin())->get(route('admin.oci.sweeper'))->assertOk();

    // pending() runs on every GET; a pending() that sweeps is a delete on page load.
    expect(OciBlob::count())->toBe(2);
});

it('reads the last run from the activity log', function () {
    $package = Package::factory()->docker()->create();
    sweeperPageBlob($package, 'sha256:stale', 48);

    // A real sweep writes the entry the page reads — no second copy of the fact anywhere.
    $this->artisan('oci:sweep')->assertSuccessful();

    $this->actingAs(superAdmin())
        ->get(route('admin.oci.sweeper'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('last_run.event', 'storage_swept')
            ->where('last_run.properties.blobs_removed', 1));
});

it('queues a sweep from the button', function () {
    Queue::fake();

    $this->actingAs(superAdmin())
        ->post(route('admin.oci.sweeper.run'))
        ->assertRedirect();

    Queue::assertPushed(SweepOciStorage::class, 1);
});

it('refuses a non-super-admin, and queues nothing', function () {
    Queue::fake();

    $this->actingAs(adminOf(Organization::factory()->create()))
        ->post(route('admin.oci.sweeper.run'))
        ->assertForbidden();

    $this->actingAs(adminOf(Organization::factory()->create()))
        ->get(route('admin.oci.sweeper'))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

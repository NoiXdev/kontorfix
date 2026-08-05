<?php

use App\Enums\UserRole;
use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FixtureRepo;

it('counts downloads and records the dist size when a dist is served', function () {
    Storage::fake('artifacts');
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($pkg);
    $headers = tokenHeaderFor($group);

    $this->withHeaders($headers)->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertOk();
    $this->withHeaders($headers)->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertOk();

    $version = $pkg->versions()->where('version', '1.0.0.0')->first();
    expect($version->download_count)->toBe(2);
    expect($version->dist_size)->toBeGreaterThan(0);
});

it('exposes aggregated usage stats on the admin package page', function () {
    $admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
    $pkg = Package::factory()->create();
    foreach ([['1.0.0.0', 3, 1000], ['1.1.0.0', 7, 2000]] as [$v, $dl, $size]) {
        $pkg->versions()->create([
            'version' => $v, 'version_pretty' => 'v'.$v, 'source_reference' => 'ref'.$v,
            'metadata' => [], 'download_count' => $dl, 'dist_size' => $size,
        ]);
    }

    $this->actingAs($admin)->get("/admin/packages/{$pkg->id}")
        ->assertInertia(fn ($page) => $page
            ->where('stats.downloads', 10)
            ->where('stats.storage_bytes', 3000)
            ->where('stats.versions', 2));
});

it('rolls usage stats up to the registry level', function () {
    $admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
    $group = Group::factory()->for(Organization::factory())->create();

    $pkg = Package::factory()->create();
    $pkg->versions()->create([
        'version' => '1.0.0.0', 'version_pretty' => 'v1', 'source_reference' => 'r1',
        'metadata' => [], 'download_count' => 42, 'dist_size' => 5000,
    ]);
    $group->packages()->attach($pkg);

    $this->actingAs($admin)->get("/admin/groups/{$group->id}")
        ->assertInertia(fn ($page) => $page
            ->where('stats.downloads', 42)
            ->where('stats.storage_bytes', 5000)
            ->where('stats.packages', 1));
});

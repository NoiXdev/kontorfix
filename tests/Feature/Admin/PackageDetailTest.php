<?php

use App\Enums\UserRole;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

it('shows a package with versions, dependencies and groups', function () {
    $group = Group::factory()->for(Organization::factory())->create(['name' => 'Kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'composer', 'name' => 'acme/widget']);
    PackageVersion::factory()->create([
        'package_id' => $pkg->id, 'version' => '1.0.0.0', 'version_pretty' => 'v1.0.0',
        'metadata' => ['require' => ['php' => '^8.2', 'monolog/monolog' => '^3.0'], 'description' => 'x'],
    ]);
    $group->packages()->attach($pkg);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/packages/Show')
            ->where('package.name', 'acme/widget')
            ->has('versions', 1)
            ->where('versions.0.version', 'v1.0.0')
            ->where('versions.0.dependencies.runtime', ['php' => '^8.2', 'monolog/monolog' => '^3.0'])
            ->has('groups', 1)
            ->where('groups.0.name', 'Kadenz'));
});

it('builds the operator install command from a registry rather than from the package name', function () {
    // F4. This tab assembled `pip install ${name}` in the .vue file — the registry-less
    // command the portal was fixed to stop printing, still being printed one page over. It
    // does not fail: pip resolves it against PyPI and installs whatever is published there
    // under that name. Asserted as a WHOLE string, because a `toContain('pip install')` is
    // satisfied by exactly the defect.
    config(['app.url' => 'https://reg.example.test']);
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create(['slug' => 'intern']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'python', 'name' => 'kernmodul']);
    $group->packages()->attach($pkg);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/packages/Show')
            ->where('install', 'pip install --index-url https://token:<token>@reg.example.test/r/acme/intern/simple/ kernmodul'));
});

it('prefers a registry with its own domain for that command, as the docker page does', function () {
    // The same choice showDocker() makes, so the two halves of this controller cannot name
    // two different addresses for one package: a custom domain is the shorter address, and it
    // carries no path prefix at all.
    config(['app.url' => 'https://reg.example.test']);
    $org = Organization::factory()->create(['slug' => 'acme']);
    $plain = Group::factory()->for($org)->create(['slug' => 'intern']);
    $domained = Group::factory()->for($org)->create(['slug' => 'extern']);
    Domain::factory()->for($domained)->create(['hostname' => 'pakete.acme.test']);

    $pkg = Package::factory()->inOrgOf($plain)->create(['type' => 'python', 'name' => 'kernmodul']);
    $plain->packages()->attach($pkg);
    $domained->packages()->attach($pkg);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/packages/Show')
            ->where('install', 'pip install --index-url https://token:<token>@pakete.acme.test/simple/ kernmodul'));
});

it('offers no install command for a package in no registry at all', function () {
    // There is no address to build one from. The tab says so; it does not fall back to the
    // registry-less form, which is the whole defect.
    $pkg = Package::factory()->create(['type' => 'python', 'name' => 'kernmodul']);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/packages/Show')->where('install', null));
});

it('is operator-gated', function () {
    $pkg = Package::factory()->create();
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    $this->actingAs($custAdmin)->get("/admin/packages/{$pkg->id}")->assertForbidden();
});

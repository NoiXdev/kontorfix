<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Enums\UserRole;
use App\Jobs\SyncMirrorPackage;
use App\Models\Group;
use App\Models\MirrorSource;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Task 8: package create/edit in mirror mode, the probe endpoint that backs it, and the
 * Show payload that reports a mirror package's source. Covers StorePackageRequest's mirror
 * rules, store()/update()'s ownership and type checks, MirrorProbe, and the sync card's
 * payload shape — see .superpowers/sdd/2026-09-09-mirror-packages/task-8-brief.md.
 */
function mirrorModeAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

// --- StorePackageRequest rules ---

it('requires mirror_source_id and mirror_name when source_mode is mirror', function () {
    $admin = mirrorModeAdmin();

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/mirrored',
        'source_mode' => 'mirror',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertSessionHasErrors(['mirror_source_id', 'mirror_name']);

    expect(Package::where('name', 'acme/mirrored')->exists())->toBeFalse();
});

it('prohibits git-only fields when source_mode is mirror', function () {
    $admin = mirrorModeAdmin();
    $source = MirrorSource::factory()->create(['organization_id' => $admin->organization_id, 'type' => PackageType::Composer]);

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/mirrored',
        'source_mode' => 'mirror',
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/mirrored',
        'repository_url' => 'https://github.com/acme/mirrored.git',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertSessionHasErrors(['repository_url']);

    expect(Package::where('name', 'acme/mirrored')->exists())->toBeFalse();
});

it('prohibits git-only fields (token/credential) when source_mode is mirror', function () {
    $admin = mirrorModeAdmin();
    $source = MirrorSource::factory()->create(['organization_id' => $admin->organization_id, 'type' => PackageType::Composer]);

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/mirrored2',
        'source_mode' => 'mirror',
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/mirrored2',
        'repository_token' => 'ghp_inline',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertSessionHasErrors(['repository_token']);
});

it('prohibits mirror fields outside mirror mode', function () {
    $admin = mirrorModeAdmin();
    $source = MirrorSource::factory()->create(['organization_id' => $admin->organization_id, 'type' => PackageType::Composer]);

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/gitmode',
        'source_mode' => 'git',
        'repository_url' => 'https://github.com/acme/gitmode.git',
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/gitmode',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertSessionHasErrors(['mirror_source_id', 'mirror_name']);

    expect(Package::where('name', 'acme/gitmode')->exists())->toBeFalse();
});

it('falls back to the type default source mode for docker rather than requiring mirror fields', function () {
    $admin = mirrorModeAdmin();

    // Docker's only allowed mode is Publish (PackageSourceMode::allowedFor) — an explicit
    // 'mirror' submission is invalid for source_mode itself, but effectiveSourceMode() must
    // still fall back to Publish rather than treating the request as mirror-mode, which
    // would otherwise wrongly demand mirror_source_id/mirror_name too.
    $response = $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'docker',
        'name' => 'acme/img',
        'source_mode' => 'mirror',
        'group_ids' => [homeRegistryId($admin)],
    ]);

    $response->assertSessionHasErrors('source_mode');
    $response->assertSessionDoesntHaveErrors(['mirror_source_id', 'mirror_name']);
});

// --- store() ---

it('creates a mirror-sourced package, persists the source and name, and dispatches a mirror sync', function () {
    Queue::fake();
    $admin = mirrorModeAdmin();
    $source = MirrorSource::factory()->create(['organization_id' => $admin->organization_id, 'type' => PackageType::Composer]);

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/mirrored',
        'source_mode' => 'mirror',
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/upstream-name',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $pkg = Package::where('name', 'acme/mirrored')->firstOrFail();
    expect($pkg->source_mode)->toBe(PackageSourceMode::Mirror)
        ->and($pkg->mirror_source_id)->toBe($source->id)
        ->and($pkg->mirror_name)->toBe('acme/upstream-name');

    Queue::assertPushed(SyncMirrorPackage::class, fn (SyncMirrorPackage $job) => $job->package->is($pkg));
});

it('refuses a mirror source belonging to another organization, and persists no package', function () {
    Queue::fake();
    $admin = mirrorModeAdmin();
    $foreignSource = MirrorSource::factory()->create(['type' => PackageType::Composer]);

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/foreign',
        'source_mode' => 'mirror',
        'mirror_source_id' => $foreignSource->id,
        'mirror_name' => 'acme/foreign',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertForbidden();

    expect(Package::where('name', 'acme/foreign')->exists())->toBeFalse();
    Queue::assertNotPushed(SyncMirrorPackage::class);
});

it('refuses a mirror source of a mismatched type, and persists no package', function () {
    Queue::fake();
    $admin = mirrorModeAdmin();
    $npmSource = MirrorSource::factory()->create(['organization_id' => $admin->organization_id, 'type' => PackageType::Npm]);

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'composer',
        'name' => 'acme/mismatch',
        'source_mode' => 'mirror',
        'mirror_source_id' => $npmSource->id,
        'mirror_name' => 'acme/mismatch',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertStatus(422);

    expect(Package::where('name', 'acme/mismatch')->exists())->toBeFalse();
    Queue::assertNotPushed(SyncMirrorPackage::class);
});

// --- update() ---

it('re-dispatches the mirror sync when mirror_source_id or mirror_name changes', function () {
    Queue::fake();
    $admin = mirrorModeAdmin();
    $group = Group::factory()->create(['organization_id' => $admin->organization_id]);
    $source = MirrorSource::factory()->create(['organization_id' => $admin->organization_id, 'type' => PackageType::Composer]);
    $pkg = Package::factory()->inOrgOf($group)->create([
        'organization_id' => $admin->organization_id,
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/old',
        'repository_url' => null,
    ]);

    $this->actingAs($admin)->put("/admin/packages/{$pkg->id}", [
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/new',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($pkg->fresh()->mirror_name)->toBe('acme/new');
    Queue::assertPushed(SyncMirrorPackage::class, fn (SyncMirrorPackage $job) => $job->package->is($pkg));
});

it('does not re-dispatch the mirror sync when nothing changed', function () {
    Queue::fake();
    $admin = mirrorModeAdmin();
    $group = Group::factory()->create(['organization_id' => $admin->organization_id]);
    $source = MirrorSource::factory()->create(['organization_id' => $admin->organization_id, 'type' => PackageType::Composer]);
    $pkg = Package::factory()->inOrgOf($group)->create([
        'organization_id' => $admin->organization_id,
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/same',
        'repository_url' => null,
    ]);

    $this->actingAs($admin)->put("/admin/packages/{$pkg->id}", [
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/same',
    ])->assertRedirect()->assertSessionHasNoErrors();

    Queue::assertNotPushed(SyncMirrorPackage::class);
});

it('rejects git fields when updating a mirror-sourced package', function () {
    $admin = mirrorModeAdmin();
    $group = Group::factory()->create(['organization_id' => $admin->organization_id]);
    $source = MirrorSource::factory()->create(['organization_id' => $admin->organization_id, 'type' => PackageType::Composer]);
    $pkg = Package::factory()->inOrgOf($group)->create([
        'organization_id' => $admin->organization_id,
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/old',
        'repository_url' => null,
    ]);

    $this->actingAs($admin)->put("/admin/packages/{$pkg->id}", [
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/old',
        'repository_url' => 'https://github.com/acme/old.git',
    ])->assertSessionHasErrors('repository_url');

    expect($pkg->fresh()->repository_url)->toBeNull();
});

// --- probeMirror() ---

it('probes a mirror source and returns the discovered name, description and versions', function () {
    $admin = mirrorModeAdmin();
    $source = MirrorSource::factory()->create([
        'organization_id' => $admin->organization_id, 'type' => PackageType::Composer, 'url' => 'https://repo.test',
    ]);

    Http::fake([
        '*/p2/acme/demo.json' => Http::response([
            'packages' => ['acme/demo' => MetadataMinifier::minify([
                ['name' => 'acme/demo', 'version' => 'v1.0.0', 'version_normalized' => '1.0.0.0', 'description' => 'Demo package'],
            ])],
        ], 200),
    ]);

    $this->actingAs($admin)->postJson('/admin/packages/probe-mirror', [
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/demo',
    ])->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('name', 'acme/demo')
        ->assertJsonPath('description', 'Demo package')
        ->assertJsonPath('versions', ['v1.0.0']);
});

it('reports a mirror package not found at the source with a german message', function () {
    $admin = mirrorModeAdmin();
    $source = MirrorSource::factory()->create([
        'organization_id' => $admin->organization_id, 'type' => PackageType::Composer, 'url' => 'https://repo.test',
    ]);

    Http::fake(['*/p2/acme/missing.json' => Http::response('', 404)]);

    $response = $this->actingAs($admin)->postJson('/admin/packages/probe-mirror', [
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/missing',
    ])->assertOk()->assertJsonPath('ok', false);

    expect($response->json('error'))->toContain('nicht gefunden')
        ->not->toContain('Upstream')->not->toContain('returned');
});

it('refuses probing a mirror source belonging to another organization', function () {
    // A plain (non-operator, non-super) admin: mirrorModeAdmin() is an operator-org admin,
    // which administers() treats as a super-admin able to reach every organization — the
    // wrong actor for a cross-organization refusal test.
    $admin = adminOf(Organization::factory()->create());
    $foreignSource = MirrorSource::factory()->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);

    $this->actingAs($admin)->postJson('/admin/packages/probe-mirror', [
        'mirror_source_id' => $foreignSource->id,
        'mirror_name' => 'acme/demo',
    ])->assertForbidden();
});

it('throttles the mirror probe endpoint the same as the git probe', function () {
    $admin = mirrorModeAdmin();
    $source = MirrorSource::factory()->create([
        'organization_id' => $admin->organization_id, 'type' => PackageType::Composer, 'url' => 'https://repo.test',
    ]);
    Http::fake(['*/p2/acme/demo.json' => Http::response(['packages' => ['acme/demo' => []]], 200)]);

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($admin)->postJson('/admin/packages/probe-mirror', [
            'mirror_source_id' => $source->id,
            'mirror_name' => 'acme/demo',
        ])->assertOk();
    }

    $this->actingAs($admin)->postJson('/admin/packages/probe-mirror', [
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/demo',
    ])->assertStatus(429);
});

it('warns that a plain-http source with a token will not send it', function () {
    $admin = mirrorModeAdmin();
    $source = MirrorSource::factory()->create([
        'organization_id' => $admin->organization_id,
        'type' => PackageType::Composer,
        'url' => 'http://repo.test',
        'auth_token' => 'secret-token',
    ]);

    Http::fake(['*/p2/acme/demo.json' => Http::response(['packages' => ['acme/demo' => []]], 200)]);

    $this->actingAs($admin)->postJson('/admin/packages/probe-mirror', [
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/demo',
    ])->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('warning', 'Quelle unverschlüsselt — Token wird nicht gesendet.');
});

it('carries no warning for an https source even with a token', function () {
    $admin = mirrorModeAdmin();
    $source = MirrorSource::factory()->create([
        'organization_id' => $admin->organization_id,
        'type' => PackageType::Composer,
        'url' => 'https://repo.test',
        'auth_token' => 'secret-token',
    ]);

    Http::fake(['*/p2/acme/demo.json' => Http::response(['packages' => ['acme/demo' => []]], 200)]);

    $response = $this->actingAs($admin)->postJson('/admin/packages/probe-mirror', [
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/demo',
    ])->assertOk()->assertJsonPath('ok', true);

    expect($response->json())->not->toHaveKey('warning');
});

// --- show() payload ---

it('carries the mirror source in the show payload for a mirror package', function () {
    $admin = mirrorModeAdmin();
    $group = Group::factory()->create(['organization_id' => $admin->organization_id]);
    $source = MirrorSource::factory()->create([
        'organization_id' => $admin->organization_id,
        'name' => 'Acme Mirror',
        'type' => PackageType::Composer,
        'auth_token' => 'super-secret-token',
    ]);
    $pkg = Package::factory()->inOrgOf($group)->create([
        'organization_id' => $admin->organization_id,
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/upstream',
        'repository_url' => null,
    ]);
    $group->packages()->attach($pkg);

    $this->actingAs($admin)->get("/admin/packages/{$pkg->id}")
        ->assertInertia(function ($page) use ($source) {
            $page->component('admin/packages/Show')
                ->where('package.mirror', ['source_id' => $source->id, 'source_name' => 'Acme Mirror', 'mirror_name' => 'acme/upstream']);

            // Walk the whole payload: the mirror source's auth token must appear nowhere.
            $payload = json_encode($page->toArray());
            expect($payload)->not->toContain('super-secret-token')->not->toContain('auth_token');
        });
});

it('carries a null mirror in the show payload for a non-mirror package', function () {
    $admin = mirrorModeAdmin();
    $group = Group::factory()->create(['organization_id' => $admin->organization_id]);
    $pkg = Package::factory()->inOrgOf($group)->create([
        'organization_id' => $admin->organization_id,
        'type' => PackageType::Npm,
        'source_mode' => PackageSourceMode::Publish,
    ]);
    $group->packages()->attach($pkg);

    $this->actingAs($admin)->get("/admin/packages/{$pkg->id}")
        ->assertInertia(fn ($page) => $page->component('admin/packages/Show')->where('package.mirror', null));
});

// --- show() payload: mirrorSources (retarget form) and the deleted-source fallback ---

it('carries the mirror source picker, scoped to the package\'s own organization and type, for a mirror package', function () {
    $admin = mirrorModeAdmin();
    $group = Group::factory()->create(['organization_id' => $admin->organization_id]);
    $source = MirrorSource::factory()->create([
        'organization_id' => $admin->organization_id, 'name' => 'Composer Mirror', 'type' => PackageType::Composer, 'auth_token' => 'top-secret',
    ]);
    // Same org, different type — must be excluded: a Composer package cannot mirror an npm source.
    MirrorSource::factory()->create(['organization_id' => $admin->organization_id, 'name' => 'Npm Mirror', 'type' => PackageType::Npm]);
    // Same type, foreign org — must be excluded: a mirror source is never shared cross-org.
    MirrorSource::factory()->create(['name' => 'Foreign Mirror', 'type' => PackageType::Composer]);

    $pkg = Package::factory()->inOrgOf($group)->create([
        'organization_id' => $admin->organization_id,
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/upstream',
        'repository_url' => null,
    ]);
    $group->packages()->attach($pkg);

    $this->actingAs($admin)->get("/admin/packages/{$pkg->id}")
        ->assertInertia(function ($page) {
            $page->component('admin/packages/Show')
                ->has('mirrorSources', 1)
                ->where('mirrorSources.0.name', 'Composer Mirror');

            $payload = json_encode($page->toArray());
            expect($payload)->not->toContain('top-secret')->not->toContain('auth_token');
        });
});

it('carries no mirror source picker (null) for a non-mirror package', function () {
    $admin = mirrorModeAdmin();
    $group = Group::factory()->create(['organization_id' => $admin->organization_id]);
    $pkg = Package::factory()->inOrgOf($group)->create([
        'organization_id' => $admin->organization_id,
        'type' => PackageType::Npm,
        'source_mode' => PackageSourceMode::Publish,
    ]);
    $group->packages()->attach($pkg);

    $this->actingAs($admin)->get("/admin/packages/{$pkg->id}")
        ->assertInertia(fn ($page) => $page->component('admin/packages/Show')->where('mirrorSources', null));
});

it('reports a null source_name (not an omitted key) when the mirror package\'s source was deleted', function () {
    $admin = mirrorModeAdmin();
    $group = Group::factory()->create(['organization_id' => $admin->organization_id]);
    $source = MirrorSource::factory()->create(['organization_id' => $admin->organization_id, 'type' => PackageType::Composer]);
    $pkg = Package::factory()->inOrgOf($group)->create([
        'organization_id' => $admin->organization_id,
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/upstream',
        'repository_url' => null,
    ]);
    $group->packages()->attach($pkg);

    // nullOnDelete: deleting the source orphans the package rather than cascading (see
    // MirrorSourceController::destroy()) — source_mode stays 'mirror', mirror_source_id and
    // the mirrorSource relation both go null.
    $source->delete();

    $this->actingAs($admin)->get("/admin/packages/{$pkg->id}")
        ->assertInertia(fn ($page) => $page->component('admin/packages/Show')
            ->where('package.mirror', ['source_id' => null, 'source_name' => null, 'mirror_name' => 'acme/upstream'])
            ->where('mirrorSources', []));
});

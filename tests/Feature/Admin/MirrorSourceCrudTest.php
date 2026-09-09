<?php

use App\Enums\PackageType;
use App\Enums\UserRole;
use App\Models\MirrorSource;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function mirrorAdmin(Organization $org): User
{
    return User::factory()->for($org)->create(['role' => UserRole::Admin]);
}

it('creates an org-scoped mirror source with an encrypted, hidden token', function () {
    $org = Organization::factory()->create();

    $this->actingAs(mirrorAdmin($org))->post('/admin/mirror-sources', [
        'name' => 'Packagist Mirror', 'type' => 'composer', 'url' => 'https://repo.example.test', 'auth_token' => 'secret-token',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $source = MirrorSource::firstOrFail();
    expect($source->auth_token)->toBe('secret-token')
        ->and($source->type)->toBe(PackageType::Composer)
        ->and($source->organization_id)->toBe($org->id)
        ->and($source->toArray())->not->toHaveKey('auth_token')
        ->and(DB::table('mirror_sources')->where('id', $source->id)->value('auth_token'))->not->toBe('secret-token');
});

it('refuses to create a Docker mirror source', function () {
    $org = Organization::factory()->create();

    $this->actingAs(mirrorAdmin($org))->post('/admin/mirror-sources', [
        'name' => 'Docker Mirror', 'type' => 'docker', 'url' => 'https://registry.example.test',
    ])->assertSessionHasErrors('type');

    expect(MirrorSource::count())->toBe(0);
});

it('lets a super-admin create a mirror source in an explicitly selected organization', function () {
    // superAdmin()'s home organization is itself an operator org — a different one than
    // $target below — so a source landing in $target proves the explicit selection was
    // honoured rather than resolveCreationOrg() falling back to the active scope/home org.
    $admin = superAdmin();
    $target = Organization::factory()->create(['is_operator' => false]);

    $this->actingAs($admin)->post('/admin/mirror-sources', [
        'name' => 'Target Org Mirror', 'type' => 'composer', 'url' => 'https://repo.example.test',
        'organization_id' => $target->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $source = MirrorSource::firstOrFail();
    expect($source->organization_id)->toBe($target->id);
});

it('refuses an unsafe URL pointing at a private address', function () {
    $org = Organization::factory()->create();

    $this->actingAs(mirrorAdmin($org))->post('/admin/mirror-sources', [
        'name' => 'Internal', 'type' => 'composer', 'url' => 'http://127.0.0.1/repo',
    ])->assertSessionHasErrors('url');

    expect(MirrorSource::count())->toBe(0);
});

it('refuses a hostname that resolves to an internal address (DNS, not just an IP literal)', function () {
    // The spec demands UrlSafety::isSafeResolving() here, not isSafe(): a mirror source is
    // configured once and then fetched from repeatedly by a background job, so a hostname
    // that only resolves internally — never caught by isSafe()'s IP-literal check alone —
    // must be refused at configuration time. FixtureHostResolver (see its own docblock)
    // resolves any `.internal` name to a private address without a real DNS lookup.
    $org = Organization::factory()->create();

    $this->actingAs(mirrorAdmin($org))->post('/admin/mirror-sources', [
        'name' => 'Internal DNS', 'type' => 'composer', 'url' => 'http://vault.internal/repo',
    ])->assertSessionHasErrors('url');

    expect(MirrorSource::count())->toBe(0);
});

it('refuses a hostname that does not resolve at all (fail-closed)', function () {
    $org = Organization::factory()->create();
    $this->resolveHostTo('nowhere.example.com', []);

    $this->actingAs(mirrorAdmin($org))->post('/admin/mirror-sources', [
        'name' => 'Unresolvable', 'type' => 'composer', 'url' => 'https://nowhere.example.com/repo',
    ])->assertSessionHasErrors('url');

    expect(MirrorSource::count())->toBe(0);
});

it('does not flash the raw auth token into old-input storage when an unrelated field fails validation', function () {
    // `type` is what fails here, not `auth_token` — the only thing standing between a
    // failed create's old-input flash and a raw mirror credential sitting in the
    // `sessions` table is bootstrap/app.php's dontFlash() list.
    $org = Organization::factory()->create();

    $this->actingAs(mirrorAdmin($org))->post('/admin/mirror-sources', [
        'name' => 'Leaky', 'type' => 'not-a-real-type', 'url' => 'https://repo.example.test',
        'auth_token' => 'super-secret-flash-token',
    ])->assertSessionHasErrors('type');

    $oldInput = session('_old_input');
    expect($oldInput)->not->toBeNull();
    expect($oldInput['auth_token'] ?? '')->not->toContain('super-secret-flash-token');
});

it('scopes the listing and blocks cross-org management', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $adminA = mirrorAdmin($orgA);
    MirrorSource::factory()->for($orgA)->create(['name' => 'mine']);
    $foreign = MirrorSource::factory()->for($orgB)->create(['name' => 'theirs']);

    $this->actingAs($adminA)->get('/admin/mirror-sources')
        ->assertInertia(fn ($p) => $p->has('sources', 1)->where('sources.0.name', 'mine'));

    $this->actingAs($adminA)->get("/admin/mirror-sources/{$foreign->id}/edit")->assertForbidden();
    $this->actingAs($adminA)->put("/admin/mirror-sources/{$foreign->id}", [
        'name' => 'x', 'type' => 'composer', 'url' => 'https://repo.example.test',
    ])->assertForbidden();
    $this->actingAs($adminA)->delete("/admin/mirror-sources/{$foreign->id}")->assertForbidden();

    expect($foreign->fresh()->name)->toBe('theirs');
});

it('never exposes the auth token on the index or edit payload', function () {
    $org = Organization::factory()->create();
    $source = MirrorSource::factory()->for($org)->create(['auth_token' => 'super-secret']);

    $this->actingAs(mirrorAdmin($org))->get('/admin/mirror-sources')
        ->assertInertia(function ($p) {
            $p->has('sources', 1);
            $payload = json_encode($p->toArray());
            expect($payload)->not->toContain('super-secret')->and($payload)->not->toContain('auth_token');
        });

    $this->actingAs(mirrorAdmin($org))->get("/admin/mirror-sources/{$source->id}/edit")
        ->assertInertia(function ($p) {
            $payload = json_encode($p->toArray());
            expect($payload)->not->toContain('super-secret')->and($payload)->not->toContain('auth_token');
        });
});

it('keeps the token on update when left blank and replaces it when provided', function () {
    $org = Organization::factory()->create();
    $source = MirrorSource::factory()->for($org)->create(['auth_token' => 'old-token']);

    $this->actingAs(mirrorAdmin($org))->put("/admin/mirror-sources/{$source->id}", [
        'name' => 'renamed', 'type' => 'composer', 'url' => 'https://repo.example.test', 'auth_token' => '',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($source->fresh()->auth_token)->toBe('old-token')
        ->and($source->fresh()->name)->toBe('renamed');

    $this->actingAs(mirrorAdmin($org))->put("/admin/mirror-sources/{$source->id}", [
        'name' => 'renamed', 'type' => 'composer', 'url' => 'https://repo.example.test', 'auth_token' => 'new-token',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($source->fresh()->auth_token)->toBe('new-token');
});

it('forbids portal members from managing mirror sources', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->get('/admin/mirror-sources')->assertForbidden();
});

it('deletes a mirror source even when packages reference it, orphaning them with a warning', function () {
    $org = Organization::factory()->create();
    $source = MirrorSource::factory()->for($org)->create();
    $package = Package::factory()->for($org)->create(['mirror_source_id' => $source->id]);

    $response = $this->actingAs(mirrorAdmin($org))->delete("/admin/mirror-sources/{$source->id}")
        ->assertRedirect();

    expect(MirrorSource::find($source->id))->toBeNull()
        ->and($package->fresh()->mirror_source_id)->toBeNull();

    $response->assertSessionHas('success', function (?string $message) {
        return $message !== null && str_contains($message, 'nächsten Synchronisierung');
    });
});

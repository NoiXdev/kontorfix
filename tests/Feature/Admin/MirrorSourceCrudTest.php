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

it('refuses an unsafe URL pointing at a private address', function () {
    $org = Organization::factory()->create();

    $this->actingAs(mirrorAdmin($org))->post('/admin/mirror-sources', [
        'name' => 'Internal', 'type' => 'composer', 'url' => 'http://127.0.0.1/repo',
    ])->assertSessionHasErrors('url');

    expect(MirrorSource::count())->toBe(0);
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

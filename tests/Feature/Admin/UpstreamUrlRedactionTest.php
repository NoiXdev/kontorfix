<?php

// `upstreams.url` is the only way to reach a Basic-auth mirror (see CredentialUrl), and
// both the index listing and the edit page sit in the `['auth', 'operator']` route group
// — reachable by any Maintainer of the organization, not just the Admin who entered the
// credential. This mirrors PackageRepositoryUrlRedactionTest for the other credential-URL
// column, and additionally covers the update round-trip: the edit page now echoes a
// redacted value back to the server on an unchanged save, and that must resolve to the
// stored URL rather than overwrite it with the literal marker.

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use App\Models\User;

const UUR_LEAKED_PASSWORD = 'sw0rdfish-leak';

/** A Maintainer — the tier that may reach the upstream console but did not enter the credential. */
function redactionMaintainerFor(Upstream $upstream): User
{
    return User::factory()
        ->for($upstream->group->organization)
        ->create(['role' => UserRole::Maintainer]);
}

it('withholds an inline mirror credential from the index listing', function () {
    $upstream = Upstream::factory()
        ->for(Group::factory()->for(Organization::factory()))
        ->create(['url' => 'https://mirror:'.UUR_LEAKED_PASSWORD.'@repo.packagist.test']);

    $response = $this->actingAs(redactionMaintainerFor($upstream))->get('/admin/upstreams')->assertOk();

    expect($response->getContent())->not->toContain(UUR_LEAKED_PASSWORD);
    $response->assertInertia(fn ($page) => $page
        ->where('upstreams.0.url', 'https://***@repo.packagist.test'));
});

it('withholds an inline mirror credential from the edit page', function () {
    $upstream = Upstream::factory()
        ->for(Group::factory()->for(Organization::factory()))
        ->create(['url' => 'https://mirror:'.UUR_LEAKED_PASSWORD.'@repo.packagist.test']);

    $response = $this->actingAs(redactionMaintainerFor($upstream))
        ->get("/admin/upstreams/{$upstream->id}/edit")->assertOk();

    expect($response->getContent())->not->toContain(UUR_LEAKED_PASSWORD);
    $response->assertInertia(fn ($page) => $page
        ->where('upstream.url', 'https://***@repo.packagist.test'));
});

it('still shows a credential-free upstream url in full — the anchor for the cases above', function () {
    // Same routes, same actor, same props: so the redaction above is of the userinfo
    // component specifically, and not the prop being blanked, dropped or gated away.
    $upstream = Upstream::factory()
        ->for(Group::factory()->for(Organization::factory()))
        ->create(['url' => 'https://repo.packagist.test']);
    $maintainer = redactionMaintainerFor($upstream);

    $this->actingAs($maintainer)->get('/admin/upstreams')->assertOk()
        ->assertInertia(fn ($page) => $page->where('upstreams.0.url', 'https://repo.packagist.test'));

    $this->actingAs($maintainer)->get("/admin/upstreams/{$upstream->id}/edit")->assertOk()
        ->assertInertia(fn ($page) => $page->where('upstream.url', 'https://repo.packagist.test'));
});

it('keeps the stored credential when the edit form is saved back with the redacted echo unchanged', function () {
    $upstream = Upstream::factory()
        ->for(Group::factory()->for(Organization::factory()))
        ->create([
            'type' => 'composer',
            'policy' => 'proxy',
            'priority' => 3,
            'url' => 'https://mirror:'.UUR_LEAKED_PASSWORD.'@repo.packagist.test',
        ]);
    $admin = User::factory()->for($upstream->group->organization)->create(['role' => UserRole::Admin]);

    // What the edit page's form actually holds: the redacted value it was rendered with,
    // exactly as CredentialUrl::redact() produces it — not the raw stored URL.
    $this->actingAs($admin)->put("/admin/upstreams/{$upstream->id}", [
        'type' => 'composer',
        'url' => 'https://***@repo.packagist.test',
        'policy' => 'proxy',
        'priority' => 3,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($upstream->fresh()->url)->toBe('https://mirror:'.UUR_LEAKED_PASSWORD.'@repo.packagist.test');
});

it('refuses a redacted url that does not match the stored credential', function () {
    // An operator who edited the path (or host) around the `***` rather than clearing it:
    // this is not the "unchanged" echo, so the marker must not be written to storage, and
    // NotRedactedCredentialUrl refuses it outright.
    $upstream = Upstream::factory()
        ->for(Group::factory()->for(Organization::factory()))
        ->create([
            'type' => 'composer',
            'policy' => 'proxy',
            'url' => 'https://mirror:'.UUR_LEAKED_PASSWORD.'@repo.packagist.test',
        ]);
    $admin = User::factory()->for($upstream->group->organization)->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->put("/admin/upstreams/{$upstream->id}", [
        'type' => 'composer',
        'url' => 'https://***@repo.packagist.test/other-path',
        'policy' => 'proxy',
    ])->assertSessionHasErrors('url');

    expect($upstream->fresh()->url)->toBe('https://mirror:'.UUR_LEAKED_PASSWORD.'@repo.packagist.test');
});

it('does not flash the raw credential into old-input storage when an unrelated field fails validation', function () {
    // `policy` is what fails here, not `url` — a submission whose url is a real
    // (uncredentialed-marker) value, so it sails through NotRedactedCredentialUrl and gets
    // included in the redirect's old-input flash like any other field. The only thing
    // standing between that flash and a raw credential sitting in the `sessions` table is
    // `bootstrap/app.php`'s `dontFlash(['url', 'repository_url'])`.
    $upstream = Upstream::factory()
        ->for(Group::factory()->for(Organization::factory()))
        ->create([
            'type' => 'composer',
            'policy' => 'proxy',
            'url' => 'https://repo.packagist.test',
        ]);
    $admin = User::factory()->for($upstream->group->organization)->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->put("/admin/upstreams/{$upstream->id}", [
        'type' => 'composer',
        'url' => 'https://mirror:'.UUR_LEAKED_PASSWORD.'@repo.packagist.test',
        'policy' => 'not-a-real-policy',
    ])->assertSessionHasErrors('policy');

    $oldInput = session('_old_input');
    expect($oldInput)->not->toBeNull();
    expect($oldInput['url'] ?? '')->not->toContain(UUR_LEAKED_PASSWORD);
});

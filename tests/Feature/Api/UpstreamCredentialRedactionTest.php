<?php

use App\Enums\ApiKeyPermission;
use App\Enums\PackageType;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * `upstreams.url` is an ordinary URL column that operators nonetheless put a secret into:
 * UpstreamClient applies the dedicated, encrypted `auth_token` as a Bearer header and
 * offers nothing else, so `https://user:password@mirror/…` is the only way to reach a
 * Basic-auth mirror (Nexus, Artifactory, private Packagist). It was echoed verbatim to
 * member-tier API keys — strictly below the admin/maintainer who configured it.
 *
 * The column cannot simply refuse userinfo: every Basic mirror that works today would
 * break on the next unrelated edit with nowhere to move the credential to. So the value
 * stays, is withheld from readers below the tier that wrote it, and
 * UpdateUpstreamRequest resolves a redacted echo of the *unchanged* value back to
 * the stored URL before validation — so an unrelated edit does not force
 * re-entering the credential. Originally leaked to member-tier API keys; later
 * found to leak to any Maintainer viewing the upstreams admin console too, not
 * just the Admin who wrote it — both are covered below.
 */
const UCR_MIRROR_URL = 'https://svc:s3cr3t-mirror-pw@nexus.corp/repository/npm-proxy';

// Asserting the rule's OWN message, not merely "there is an error on this field": the
// generic `url:` rule already refuses `*` in userinfo, so a bare assertSessionHasErrors
// would pass with NotRedactedCredentialUrl removed and would prove nothing.
const UCR_WRITEBACK_ERROR = 'Die URL enthält noch den Platzhalter für ausgeblendete Zugangsdaten. Bitte die vollständige URL eintragen oder das Feld unverändert lassen.';

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->admin = User::factory()->for($this->org)->create(['role' => UserRole::Admin]);
    $this->member = User::factory()->for($this->org)->create(['role' => UserRole::Member]);

    $this->group = Group::factory()->for($this->org)->create(['slug' => 'kadenz']);
    $this->upstream = Upstream::factory()->for($this->group)->create([
        'type' => PackageType::Composer, 'url' => UCR_MIRROR_URL,
    ]);

    [, $this->memberKey] = ApiKey::issue($this->member, 'r', ApiKeyPermission::Read);
});

it('does not serve the mirror credential to a member-tier api key', function () {
    $this->withToken($this->memberKey)
        ->getJson("/api/v1/groups/{$this->group->id}/upstreams")
        ->assertOk()
        ->assertJsonPath('data.0.url', 'https://***@nexus.corp/repository/npm-proxy')
        ->assertDontSee('s3cr3t-mirror-pw');
});

it('does not repeat the mirror credential on the registry detail page', function () {
    // Display-only surface: the add-upstream form on this page starts empty, so nothing
    // here can write the marker back.
    $this->actingAs($this->admin)->get("/admin/groups/{$this->group->id}")
        ->assertInertia(fn ($p) => $p->where('upstreams.0.url', 'https://***@nexus.corp/repository/npm-proxy'));
});

it('withholds the mirror credential from the upstreams console too, and round-trips it intact on an unrelated save', function () {
    // The upstreams console sits in the `['auth', 'operator']` route group — reachable by
    // any Maintainer of the organization, not just the Admin who entered the credential —
    // so it is redacted like every other reader below the tier that wrote it.
    $this->actingAs($this->admin)->get('/admin/upstreams')
        ->assertInertia(fn ($p) => $p->where('upstreams.0.url', 'https://***@nexus.corp/repository/npm-proxy'));

    // The edit page's form is pre-filled with exactly that redacted value and echoes it
    // back unchanged on an unrelated save (priority here). UpdateUpstreamRequest resolves
    // that echo to the stored URL before validation (see its prepareForValidation()), so
    // this does not force the operator to re-enter the credential.
    $this->actingAs($this->admin)->put("/admin/upstreams/{$this->upstream->id}", [
        'type' => 'composer', 'url' => 'https://***@nexus.corp/repository/npm-proxy', 'policy' => 'proxy', 'priority' => 5,
    ])->assertSessionHasNoErrors();

    expect($this->upstream->fresh()->url)->toBe(UCR_MIRROR_URL);
});

it('refuses a redacted value that does not match the stored credential', function () {
    // Not the "unchanged" echo — an operator edited the host (or path) around the marker
    // rather than clearing it. The write must be refused, not silently overwrite the
    // credential with a literal `***`.
    $this->actingAs($this->admin)
        ->put("/admin/upstreams/{$this->upstream->id}", [
            'type' => 'composer', 'url' => 'https://***@evil.corp/repository/npm-proxy',
            'policy' => 'proxy',
        ])
        ->assertSessionHasErrors(['url' => UCR_WRITEBACK_ERROR]);

    expect($this->upstream->fresh()->url)->toBe(UCR_MIRROR_URL);
});

it('refuses a redacted value on creation too', function () {
    $this->actingAs($this->admin)
        ->post('/admin/upstreams', [
            'group_id' => $this->group->id, 'type' => 'composer',
            'url' => 'https://***@mirror.corp/npm', 'policy' => 'proxy',
        ])
        ->assertSessionHasErrors(['url' => UCR_WRITEBACK_ERROR]);
});

it('still accepts a real credential url, so no working mirror is locked out', function () {
    $this->actingAs($this->admin)
        ->put("/admin/upstreams/{$this->upstream->id}", [
            'type' => 'composer', 'url' => UCR_MIRROR_URL, 'policy' => 'proxy',
        ])
        ->assertSessionHasNoErrors();

    expect($this->upstream->fresh()->url)->toBe(UCR_MIRROR_URL);
});

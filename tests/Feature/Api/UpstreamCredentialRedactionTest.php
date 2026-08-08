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
 * stays, and is withheld from readers below the tier that wrote it.
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

it('still shows the operator the stored value so it can be read back and re-entered', function () {
    // The upstreams console is the tier that wrote the credential, and its edit dialog
    // pre-fills `form.url` from exactly this prop — redacting here would make the
    // operator write the marker back over their own secret on the next unrelated save.
    $this->actingAs($this->admin)->get('/admin/upstreams')
        ->assertInertia(fn ($p) => $p->where('upstreams.0.url', UCR_MIRROR_URL));
});

it('refuses a redacted value written back over the stored credential', function () {
    $this->actingAs($this->admin)
        ->put("/admin/upstreams/{$this->upstream->id}", [
            'type' => 'composer', 'url' => 'https://***@nexus.corp/repository/npm-proxy',
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

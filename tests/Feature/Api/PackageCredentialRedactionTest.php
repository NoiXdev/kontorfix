<?php

use App\Enums\ApiKeyPermission;
use App\Enums\PackageType;
use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * `packages.repository_url` carries a git PAT whenever an admin writes it as userinfo
 * instead of into the dedicated `repository_token` column (which is encrypted and
 * `$hidden`). The validator accepts that form and the sync path needs it to keep working,
 * so the value stays — but it was served verbatim to member-tier API keys and, worse, as
 * Composer `source.url` to every registry read token and to anonymous clients of a public
 * group. Same column, same helper, same rule as the upstream mirror credential.
 */
const PCR_PAT_URL = 'https://x:ghp_AAAABBBBCCCCDDDDEEEE@github.com/acme/private.git';

const PCR_REDACTED = 'https://***@github.com/acme/private.git';

// The rule's OWN message: the generic `url:` rule already refuses `*` in userinfo, so a
// bare assertSessionHasErrors would pass with NotRedactedCredentialUrl removed.
const PCR_WRITEBACK_ERROR = 'Die URL enthält noch den Platzhalter für ausgeblendete Zugangsdaten. Bitte die vollständige URL eintragen oder das Feld unverändert lassen.';

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->admin = User::factory()->for($this->org)->create(['role' => UserRole::Admin]);
    $this->member = User::factory()->for($this->org)->create(['role' => UserRole::Member]);

    $this->group = Group::factory()->for($this->org)->create(['slug' => 'kadenz']);
    $this->package = Package::factory()->inOrgOf($this->group)->create([
        'name' => 'acme/demo', 'type' => PackageType::Composer, 'repository_url' => PCR_PAT_URL,
    ]);
    $this->group->packages()->attach($this->package);

    [, $this->memberKey] = ApiKey::issue($this->member, 'r', ApiKeyPermission::Read);
});

it('does not serve the repository pat to a member-tier api key', function () {
    $this->withToken($this->memberKey)->getJson('/api/v1/packages')
        ->assertOk()
        ->assertJsonPath('data.0.repository_url', PCR_REDACTED)
        ->assertDontSee('ghp_AAAABBBBCCCCDDDDEEEE');

    $this->withToken($this->memberKey)->getJson("/api/v1/packages/{$this->package->id}")
        ->assertOk()
        ->assertJsonPath('data.repository_url', PCR_REDACTED)
        ->assertDontSee('ghp_AAAABBBBCCCCDDDDEEEE');
});

it('does not serve the repository pat through the package health endpoint', function () {
    $this->package->update(['sync_status' => SyncStatus::Failed]);

    $this->withToken($this->memberKey)->getJson('/api/v1/status/packages')
        ->assertOk()
        ->assertJsonPath('data.failed.0.repository_url', PCR_REDACTED)
        ->assertDontSee('ghp_AAAABBBBCCCCDDDDEEEE');
});

it('does not serve the repository pat as composer source metadata', function () {
    // The widest reader set of the three: every registry read token, and anonymous
    // clients when the group is public. A PAT here ends up in a consumer's lock file.
    PackageVersion::factory()->for($this->package)->create();

    $res = $this->withHeaders(tokenHeaderFor($this->group))
        ->getJson('/r/kadenz/p2/acme/demo.json')->assertOk();

    expect($res->getContent())->not->toContain('ghp_AAAABBBBCCCCDDDDEEEE');
    expect($res->json('packages')['acme/demo'][0]['source']['url'])->toBe(PCR_REDACTED);
});

it('withholds the stored credential from the operator tier too', function () {
    // Reversed from the original assertion, deliberately. The reason it gave — that
    // admin/packages/Show.vue pre-fills `sourceForm.repository_url` from this prop, so
    // redacting would overwrite the PAT with the marker on the next save — was answered by
    // NotRedactedCredentialUrl in the same commit: the marker is REFUSED, never stored. And
    // this prop is not only read by the tier that wrote it. A package shared into a
    // registry another organization administers makes its admin a reader here, and the
    // value survives in the activity log after rotation. The echo-back case below is what
    // keeps the form usable.
    $this->actingAs($this->admin)->get("/admin/packages/{$this->package->id}")
        ->assertInertia(fn ($p) => $p->where('package.repository_url', PCR_REDACTED))
        ->assertDontSee('ghp_AAAABBBBCCCCDDDDEEEE');
});

it('takes the redacted value the form was shown as "unchanged"', function () {
    $this->actingAs($this->admin)
        ->put("/admin/packages/{$this->package->id}", ['repository_url' => PCR_REDACTED])
        ->assertSessionHasNoErrors();

    // Not destroyed, not half-written: the stored credential is exactly as it was.
    expect($this->package->fresh()->repository_url)->toBe(PCR_PAT_URL);
});

it('refuses a redacted value written back over the stored credential', function () {
    // A marker that is NOT the echo of what is stored: the operator edited the path around
    // it, or a client invented one. Either way it names no credential, so it cannot replace
    // the one that is there.
    $this->actingAs($this->admin)
        ->put("/admin/packages/{$this->package->id}", ['repository_url' => 'https://***@github.com/acme/somewhere-else.git'])
        ->assertSessionHasErrors(['repository_url' => PCR_WRITEBACK_ERROR]);

    expect($this->package->fresh()->repository_url)->toBe(PCR_PAT_URL);
});

it('refuses a redacted value on creation too', function () {
    $this->actingAs($this->admin)
        ->post('/admin/packages', [
            'type' => 'composer', 'name' => 'acme/other',
            'repository_url' => 'https://***@github.com/acme/other.git',
        ])
        ->assertSessionHasErrors(['repository_url' => PCR_WRITEBACK_ERROR]);
});

it('still accepts a real credential url, so no working mirror is locked out', function () {
    $this->actingAs($this->admin)
        ->put("/admin/packages/{$this->package->id}", ['repository_url' => PCR_PAT_URL])
        ->assertSessionHasNoErrors();

    expect($this->package->fresh()->repository_url)->toBe(PCR_PAT_URL);
});

<?php

use App\Enums\PackageType;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;

/**
 * The instance's own host: the address at which a registry is reached by PATH namespace
 * rather than by a hostname of its own, and therefore the address at which the bare
 * `GET /v2/` names no registry at all. Read from `config('app.url')` rather than written
 * out; declared locally (not shared with tests/Feature/Oci/PathAddressingTest.php's
 * identical helper) because a top-level function only exists once its declaring file has
 * been required, so borrowing that one would break running this file on its own.
 */
function authInstanceAddress(string $path): string
{
    return rtrim((string) config('app.url'), '/').$path;
}

beforeEach(function () {
    $this->org = Organization::factory()->create([
        'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
    ]);
    $this->group = Group::factory()->for($this->org)->create();
    Domain::create(['group_id' => $this->group->id, 'hostname' => 'images.test']);
});

it('challenges an anonymous client with Basic, which is what makes docker login send credentials', function () {
    $this->get('http://images.test/v2/')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="kontorfix"')
        ->assertHeader('Docker-Distribution-Api-Version', 'registry/2.0')
        ->assertJsonPath('errors.0.code', 'UNAUTHORIZED');
});

it('accepts the token as a basic-auth password', function () {
    $plain = tokenPlainTextFor($this->group);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('anything:'.$plain)])
        ->get('http://images.test/v2/')
        ->assertOk()
        ->assertHeader('Docker-Distribution-Api-Version', 'registry/2.0');
});

it('names no registry on a host that is not one, so /v2/ advertises nothing about the instance', function () {
    // This used to be a flat 404, back when `/v2/` lived inside the domain-access group and
    // `registry.context` refused every host without a `domains` row. Path addressing makes
    // any non-domain host — the instance's own included — a host on which a registry is
    // named by the URL rather than by the hostname, so the bare version check can no longer
    // answer "no such registry": nothing has been named yet. It answers the protocol
    // handshake (see VersionController) and nothing else.
    //
    // What the old assertion was actually protecting is still protected, and asserted here
    // directly: no registry is resolvable on such a host without naming one, so nothing
    // about this instance is advertised. Which hosts reach the application at all is
    // TrustHosts' job (App\Services\Http\TrustedHosts — the APP_URL host, the loopback
    // names, and every attached hostname), not this endpoint's.
    $this->get('http://unknown.test/v2/')
        ->assertOk()
        ->assertHeader('Docker-Distribution-Api-Version', 'registry/2.0');

    $response = $this->withHeaders(['Authorization' => 'Basic '.base64_encode('x:'.tokenPlainTextFor($this->group))])
        ->get('http://unknown.test/v2/app/manifests/1.0')
        ->assertNotFound();

    // A plain 404, not an `errors[]` envelope: `app` on this host named no organization and
    // no registry, so there is nothing to report about it.
    expect($response->headers->get('Content-Type'))->toStartWith('text/html');
});

it('answers the bare version check anonymously on the instance host, where no registry is named yet', function () {
    // The three-state table for the bare `/v2/` where the address carries no registry.
    // State one: no credentials at all — the shape a real client's very first request is,
    // and it MUST be 200. A 401 here makes a public registry unpullable by every real
    // client, which is exactly the bug VersionController's own docblock records having
    // shipped once; on the instance host there is not even a group whose `public` flag
    // could excuse it.
    $this->get(authInstanceAddress('/v2/'))
        ->assertOk()
        ->assertHeader('Docker-Distribution-Api-Version', 'registry/2.0');
});

it('answers the bare version check for a valid token on the instance host', function () {
    // State two: a credential that resolves. `docker login <instance>` succeeds, and it
    // should — the token is real, even though the URL has not named which registry it will
    // be used against.
    $plain = tokenPlainTextFor($this->group);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('x:'.$plain)])
        ->get(authInstanceAddress('/v2/'))
        ->assertOk()
        ->assertHeader('Docker-Distribution-Api-Version', 'registry/2.0');
});

it('challenges credentials that resolve to no token on the instance host', function () {
    // State three, and the reason the anonymous 200 above is not simply "always 200":
    // `docker login` reports success on whatever this endpoint answers 200 to, so a wrong
    // password answered with 200 is reported to the user as a successful login and only
    // fails much later, on the push, with nothing pointing at the credential.
    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('x:definitely-not-a-token')])
        ->get(authInstanceAddress('/v2/'))
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="kontorfix"')
        ->assertJsonPath('errors.0.code', 'UNAUTHORIZED');

    // The same for the Bearer form, which is what every non-Docker client of this registry
    // sends: AuthenticateRegistry resolves both into the same null token, so both have to
    // reach the same answer.
    $this->withHeaders(['Authorization' => 'Bearer definitely-not-a-token'])
        ->get(authInstanceAddress('/v2/'))
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="kontorfix"');
});

it('404s a registry whose organization has not enabled the docker type', function () {
    $this->org->update(['enabled_registry_types' => ['composer']]);
    $plain = tokenPlainTextFor($this->group);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('x:'.$plain)])
        ->get('http://images.test/v2/')
        ->assertNotFound();
});

it('never registers /v2/ under the slug path, because a docker client cannot address one', function () {
    $plain = tokenPlainTextFor($this->group);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('x:'.$plain)])
        ->get(registryPath($this->group).'/v2/')
        ->assertNotFound();
});

it('answers the version check anonymously for a public registry, exactly as it already did for a manifest read', function () {
    // The scenario nothing tested before this fix: VersionController threw 401 for a null
    // token UNCONDITIONALLY, while ResolvesOciRepository::ociRepository() already
    // implemented and documented anonymous reads for a public group. A real client (real
    // docker/skopeo/crane, not curl aimed straight at a manifest URL) always asks GET
    // /v2/ FIRST and gives up on anything but 200 or a 401 it can retry with credentials
    // — so with the two disagreeing, a public Docker registry was unusable by any actual
    // client even though the manifest endpoint beneath it was already correctly public.
    $publicGroup = Group::factory()->for($this->org)->create(['public' => true]);
    Domain::create(['group_id' => $publicGroup->id, 'hostname' => 'public-images.test']);
    $pkg = Package::factory()->inOrgOf($publicGroup)->create(['type' => PackageType::Docker, 'name' => 'app']);
    $publicGroup->packages()->attach($pkg);

    // No Authorization header at all — genuinely anonymous, the shape a real client's
    // FIRST request against an unconfigured registry actually is.
    $this->get('http://public-images.test/v2/')
        ->assertOk()
        ->assertHeader('Docker-Distribution-Api-Version', 'registry/2.0');

    // The version check succeeding is the whole point only if what it gates was already
    // reachable too — proving both layers now agree, not merely that this one changed.
    // MANIFEST_UNKNOWN, not NAME_UNKNOWN: the repository itself resolved fine anonymously
    // (the property already in place before this fix), only the tag does not exist.
    $this->get('http://public-images.test/v2/app/manifests/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'MANIFEST_UNKNOWN');
});

it('still challenges an anonymous client for a NON-public registry, even though the public case above now succeeds', function () {
    // The fix must not overcorrect into making every registry's version check anonymous —
    // only a genuinely public one. $this->group (beforeEach) is not public, so this is the
    // same assertion as "challenges an anonymous client with Basic" above, restated here to
    // sit next to the public case and make the contrast explicit.
    $this->get('http://images.test/v2/')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="kontorfix"');
});

it('challenges a wrong password on a PUBLIC registry domain instead of reporting a login success', function () {
    // The asymmetry this closes: `canAccessGroup(null, $publicGroup)` is true, so on a public
    // registry a credential that resolved to NO token used to be indistinguishable from an
    // anonymous caller and got the same 200 — which is precisely what `docker login` reports
    // to the user as a successful login. The failure then surfaced on the next push, with
    // nothing pointing at the password. VersionController's own docblock argued why this must
    // not happen while three lines above it the domain branch did it anyway.
    $publicGroup = Group::factory()->for($this->org)->create(['public' => true]);
    Domain::create(['group_id' => $publicGroup->id, 'hostname' => 'pub.test']);

    // State one: no credentials at all — still 200, or a public registry is unpullable by
    // every real client. The fix must not be "always 401 for a null token".
    $this->get('http://pub.test/v2/')
        ->assertOk()
        ->assertHeader('Docker-Distribution-Api-Version', 'registry/2.0');

    // State two: a credential that resolves.
    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('x:'.tokenPlainTextFor($publicGroup))])
        ->get('http://pub.test/v2/')
        ->assertOk();

    // State three: a credential that resolves to nothing — the finding.
    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('x:nope')])
        ->get('http://pub.test/v2/')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="kontorfix"')
        ->assertJsonPath('errors.0.code', 'UNAUTHORIZED');

    // …and the Bearer form, which every non-Docker client of this registry sends.
    $this->withHeaders(['Authorization' => 'Bearer nope'])
        ->get('http://pub.test/v2/')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="kontorfix"');
});

it('answers the same three credential states on a PRIVATE registry domain', function () {
    // The private half of the table, stated beside the public one so the two cannot drift.
    // Anonymous is the one row that differs, and it differs because the GROUP refuses it,
    // not because a credential failed.
    $this->get('http://images.test/v2/')->assertStatus(401);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('x:'.tokenPlainTextFor($this->group))])
        ->get('http://images.test/v2/')
        ->assertOk();

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('x:nope')])
        ->get('http://images.test/v2/')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="kontorfix"');
});

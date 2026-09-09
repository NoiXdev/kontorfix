<?php

use App\Enums\TokenAbility;
use App\Http\Controllers\Registry\Oci\ManifestController;
use App\Http\Controllers\Registry\Oci\VersionController;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $org = Organization::factory()->create([
        'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
    ]);
    $this->group = Group::factory()->for($org)->create();
    Domain::create(['group_id' => $this->group->id, 'hostname' => 'images.test']);
    $this->auth = ['Authorization' => 'Basic '.base64_encode('x:'.tokenPlainTextFor($this->group))];
});

it('routes a repository name containing slashes', function () {
    // team/app is a legal OCI name; the route parameter must accept the slash rather than
    // treating it as a path separator.
    $this->withHeaders($this->auth)
        ->get('http://images.test/v2/team/app/manifests/1.0')
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');
});

it('rejects an uppercase repository name at the route, not in a controller', function () {
    $this->withHeaders($this->auth)
        ->get('http://images.test/v2/Team/App/manifests/1.0')
        ->assertNotFound()
        ->assertJsonMissingPath('errors');
});

it('accepts a digest reference and a tag reference', function () {
    foreach (['1.4.0', 'sha256:'.str_repeat('a', 64)] as $reference) {
        $this->withHeaders($this->auth)
            ->get("http://images.test/v2/app/manifests/{$reference}")
            ->assertStatus(404)
            ->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');
    }
});

it('does not let the /v2 fallback route leak outside its own prefix group', function () {
    // Pinning check for the Route::fallback() registered inside the docker `/v2` group
    // (see routes/registry.php): it exists to turn a routing miss UNDER /v2 into a bare
    // JSON 404 instead of Laravel's default HTML error page. If it were ever hoisted out
    // of that `->prefix('/v2')` group, it would silently swallow every 404 on this host —
    // registry and web UI alike — with that same empty envelope, and none of this suite's
    // other tests would notice, since they only ever request paths under /v2 themselves.
    $this->withHeaders($this->auth)
        ->get('http://images.test/definitely/not/a/real/path')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8');
});

it('routes a malformed upload id away from the controller entirely, never to a Postgres error', function () {
    // uploadId used to be constrained by `[0-9a-f-]{36}` — satisfied by 36 hyphens as much
    // as by a real UUID — rather than this file's own `$uuid` pattern. A value shaped like
    // that reached `OciBlobUpload::where('id', $uploadId)`, unconstrained, and landed on a
    // Postgres `uuid` comparison: SQLSTATE[22P02], an unrendered QueryException, a 500 with
    // a stack trace — precisely the failure this file's header comment names as the reason
    // `$uuid` exists.
    //
    // GET rather than PATCH/PUT (the methods this URI shape is actually registered under):
    // Laravel's URI-pattern matching is method-agnostic — a `where()` constraint rejects a
    // segment identically no matter which verb asks — so a GET miss here proves the route
    // pattern itself no longer recognises 36 hyphens as an upload id, which is exactly what
    // stops PATCH/PUT from ever reaching the controller with it. Asserted via GET, not the
    // real verbs, because those hit Route::fallback()'s own GET/HEAD-only registration as
    // an "alternate verb" match and answer 405 instead of falling through to the fallback —
    // correct (see "answers a method mismatch under /v2 with 405" above), but a 405 in that
    // shape depends on exactly which other routes are registered, not on this constraint.
    $auth = ['Authorization' => 'Basic '.base64_encode('x:'.tokenPlainTextFor($this->group, TokenAbility::Publish))];

    $this->withHeaders($auth)
        ->get('http://images.test/v2/app/blobs/uploads/'.str_repeat('-', 36))
        ->assertNotFound()
        ->assertJsonMissingPath('errors');
});

it('answers a method mismatch under /v2 with 405, not the fallback 404', function () {
    // routes/registry.php's own comment used to claim the opposite: that Route::fallback()
    // (registered inside this /v2 group for a genuine routing MISS) also catches a
    // wrong-method request against an otherwise-valid path, answering 404. It does not —
    // Laravel's router resolves the URI against every registered method BEFORE ever
    // considering a fallback route, via RouteCollection::checkForAlternateVerbs(), and
    // throws MethodNotAllowedHttpException (405) once it finds the path registered under
    // a different verb. Verified directly: a POST to the manifest GET/HEAD/PUT route
    // answers 405 with an `Allow` header naming the methods that DO match, never reaching
    // the fallback's bare `{}` body at all.
    $this->withHeaders($this->auth)
        ->post('http://images.test/v2/app/manifests/1.0')
        ->assertStatus(405)
        ->assertHeader('Allow', 'GET, HEAD, PUT');
});

it('resolves /v2 to the OCI routes on either host kind, never to the npm packument catch-all', function () {
    // routes/registry.php's own header records this trap being sprung once: npm's bare
    // `/{package}` catch-all (`(?!packages\.json$)[a-z0-9._-]+`) matches "v2" as a package
    // name, so a `/v2` group registered after `$registryEndpoints()` is dead code. The
    // registration point MOVED with path addressing — the OCI group left the domain-access
    // group and now sits at the top of the file — so the property has to be re-established,
    // not inherited. Asserted the way that comment says it was verified: against the route
    // collection directly, rather than by reading the file.
    foreach ([
        'http://images.test/v2/app/manifests/1.0',
        rtrim((string) config('app.url'), '/').'/v2/3b/intern/app/manifests/1.0',
    ] as $url) {
        expect(Route::getRoutes()->match(Request::create($url, 'GET'))->getActionName())
            ->toStartWith(ManifestController::class);
    }

    expect(Route::getRoutes()->match(Request::create('http://images.test/v2/', 'GET'))->getActionName())
        ->toStartWith(VersionController::class);
});

it('answers a routing miss under /v2 on the instance host with the same bare JSON 404', function () {
    // The `/v2` Route::fallback() sits inside the same group the resolver runs on, and it
    // carries no `{name}` parameter at all — so on a non-domain host there is nothing for
    // ResolveOciContext to split, and it must pass the request through rather than abort on
    // "fewer than three segments". Otherwise a routing miss on the instance host would
    // answer Laravel's HTML error page while the identical miss on a registry domain
    // answered the bare JSON envelope this fallback exists to produce.
    $this->withHeaders($this->auth)
        ->get(rtrim((string) config('app.url'), '/').'/v2/Team/App/manifests/1.0')
        ->assertNotFound()
        ->assertJsonMissingPath('errors');
});

<?php

use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;

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

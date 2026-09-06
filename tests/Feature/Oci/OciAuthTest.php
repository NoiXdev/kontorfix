<?php

use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;

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

it('404s a host that is not a registry, so /v2/ does not advertise the instance', function () {
    $this->get('http://unknown.test/v2/')->assertNotFound();
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

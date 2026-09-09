<?php

use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Registry\RegistryUrl;
use App\Services\Registry\SetupSnippetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
});

it('builds composer, auth and npm snippets for a slug-based registry', function () {
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'kunde']))->create(['slug' => 'acme']);
    $snips = (new SetupSnippetBuilder(app(RegistryUrl::class)))->for($group->fresh());

    expect($snips['composer'])->toContain('"type": "composer"')
        ->toContain('https://reg.example.test/r/kunde/acme');
    expect($snips['auth'])->toContain('reg.example.test')
        ->toContain('<token>');
    // Scoped, not global: a bare `registry=` line would route every public package
    // through this registry and make each one depend on an upstream proxy.
    expect($snips['npm'])->toContain('@<scope>:registry=https://reg.example.test/r/kunde/acme/')
        ->and($snips['npm'])->not->toContain("\nregistry=")
        ->toContain('//reg.example.test/r/kunde/acme/:_authToken=<token>');
});

it('builds pip and twine snippets for the Python registry', function () {
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'kunde']))->create(['slug' => 'acme']);
    $snips = (new SetupSnippetBuilder(app(RegistryUrl::class)))->for($group->fresh());

    expect($snips['pip'])
        ->toContain('--index-url')
        // The command line keeps the inline form (pip accepts nothing else there)…
        ->toContain('https://token:<token>@reg.example.test/r/kunde/acme/simple/')
        // …but the persistent config must not carry the token: pip.conf gets the bare
        // index URL and the credential goes to ~/.netrc.
        ->toContain("index-url = https://reg.example.test/r/kunde/acme/simple/\n")
        ->toContain('machine reg.example.test');
    expect($snips['twine'])
        ->toContain('[distutils]')
        ->toContain('repository = https://reg.example.test/r/kunde/acme/')
        ->toContain('username = token');
});

it('addresses docker on the instance host, with both slugs, when the registry has no domain', function () {
    // The premise this case used to assert the opposite of. `/v2/` sits at the root of a
    // host, so a registry without a domain of its own once had no address a Docker client
    // could use at all and `dockerHost` was null. ResolveOciContext ended that: the
    // instance's own host serves `/v2/`, and the two slugs ride along as the leading
    // segments of the repository name.
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'kunde']))->create(['slug' => 'acme']);
    $snips = (new SetupSnippetBuilder(app(RegistryUrl::class)))->for($group->fresh());

    expect($snips['dockerHost'])->toBe('reg.example.test')
        // NOT `/r/kunde/acme`: that is the Composer/npm/Python address. An image reference
        // carries no leading slash and no `/r`.
        ->and($snips['dockerRepositoryPrefix'])->toBe('kunde/acme/')
        ->and($snips['dockerHasDomain'])->toBeFalse()
        ->and($snips['dockerExample'])->toBeNull();
});

it('keeps the port of a development instance in the docker host', function () {
    // parse_url's PHP_URL_HOST drops it, and an image reference that names the wrong port
    // reaches nothing. host() (composer/npm/pip) has always dropped it; dockerHost() must
    // not, which is why the two are separate methods rather than one.
    config(['app.url' => 'http://localhost:8099']);
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'kunde']))->create(['slug' => 'acme']);
    $snips = (new SetupSnippetBuilder(app(RegistryUrl::class)))->for($group->fresh());

    expect($snips['dockerHost'])->toBe('localhost:8099');
});

it('drops the namespace and uses the domain once the registry has one', function () {
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'kunde']))->create(['slug' => 'acme']);
    Domain::factory()->for($group)->create(['hostname' => 'images.acme.test']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    $snips = (new SetupSnippetBuilder(app(RegistryUrl::class)))->for($group->fresh());

    expect($snips['dockerHost'])->toBe('images.acme.test')
        // Empty, not `kunde/acme/`: a custom domain is the registry root, so the repository
        // name reaches it bare. This is the assertion that keeps the two addressing modes
        // from being conflated into one string.
        ->and($snips['dockerRepositoryPrefix'])->toBe('')
        ->and($snips['dockerHasDomain'])->toBeTrue()
        ->and($snips['dockerExample'])->toBe('meinapp');
});

<?php

use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Registry\SetupSnippetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
});

it('builds composer, auth and npm snippets for a slug-based registry', function () {
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'kunde']))->create(['slug' => 'acme']);
    $snips = app(SetupSnippetBuilder::class)->for($group->fresh());

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
    $snips = app(SetupSnippetBuilder::class)->for($group->fresh());

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
    $snips = app(SetupSnippetBuilder::class)->for($group->fresh());

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
    $snips = app(SetupSnippetBuilder::class)->for($group->fresh());

    expect($snips['dockerHost'])->toBe('localhost:8099');
});

it('drops the namespace and uses the domain once the registry has one', function () {
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'kunde']))->create(['slug' => 'acme']);
    Domain::factory()->for($group)->create(['hostname' => 'images.acme.test']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    $snips = app(SetupSnippetBuilder::class)->for($group->fresh());

    expect($snips['dockerHost'])->toBe('images.acme.test')
        // Empty, not `kunde/acme/`: a custom domain is the registry root, so the repository
        // name reaches it bare. This is the assertion that keeps the two addressing modes
        // from being conflated into one string.
        ->and($snips['dockerRepositoryPrefix'])->toBe('')
        ->and($snips['dockerHasDomain'])->toBeTrue()
        ->and($snips['dockerExample'])->toBe('meinapp');
});

describe('forOrganization', function () {
    it('builds composer, auth, npm and pip snippets pointing at the org path', function () {
        $organization = Organization::factory()->create(['slug' => 'kunde']);
        Group::factory()->for($organization)->create(['slug' => 'acme']);

        $snips = app(SetupSnippetBuilder::class)->forOrganization($organization->fresh());

        expect($snips['composer'])->toContain('"type": "composer"')
            ->toContain('https://reg.example.test/o/kunde');
        expect($snips['auth'])->toContain('reg.example.test')
            ->toContain('<token>');
        expect($snips['npm'])->toContain('@<scope>:registry=https://reg.example.test/o/kunde/')
            ->and($snips['npm'])->toContain('//reg.example.test/o/kunde/:_authToken=<token>');
        expect($snips['pip'])->toContain('--index-url')
            ->toContain('https://token:<token>@reg.example.test/o/kunde/simple/')
            ->toContain("index-url = https://reg.example.test/o/kunde/simple/\n")
            ->toContain('machine reg.example.test');
    });

    it('derives npm scopes from packages reachable anywhere in the organization', function () {
        $organization = Organization::factory()->create(['slug' => 'kunde']);
        $groupA = Group::factory()->for($organization)->create(['slug' => 'acme']);
        $groupB = Group::factory()->for($organization)->create(['slug' => 'other']);
        $pkg = Package::factory()->inOrgOf($groupA)->create(['type' => 'npm', 'name' => '@acme/widget']);
        $groupB->packages()->attach($pkg);

        $snips = app(SetupSnippetBuilder::class)->forOrganization($organization->fresh());

        expect($snips['npm'])->toContain('@acme:registry=https://reg.example.test/o/kunde/')
            ->and($snips['npm'])->not->toContain('@<scope>');
    });

    // Publish is per-registry (a token minted here cannot say which one of the org's
    // registries an upload should target), so the org-wide snippet set never offers a
    // twine block at all — unlike for(Group), which always includes one. This must hold
    // even with Python enabled, which is exactly what this case builds.
    it('never includes a twine section for the organization-wide snippet set', function () {
        $organization = Organization::factory()->create(['enabled_registry_types' => ['python']]);
        Group::factory()->for($organization)->create();

        $snips = app(SetupSnippetBuilder::class)->forOrganization($organization->fresh());

        expect($snips)->toHaveKey('pip')->not->toHaveKey('twine');
    });

    it('lists every group of the organization for docker, collection groups included', function () {
        $organization = Organization::factory()->create(['slug' => 'kunde']);
        $visible = Group::factory()->for($organization)->create(['slug' => 'acme', 'portal_enabled' => true]);
        $collection = Group::factory()->for($organization)->create(['slug' => 'intern', 'portal_enabled' => false]);

        $snips = app(SetupSnippetBuilder::class)->forOrganization($organization->fresh());

        expect($snips['dockerHost'])->toBe('reg.example.test');
        $groups = collect($snips['dockerGroups'])->keyBy('slug');
        expect($groups)->toHaveCount(2)
            ->and($groups['acme'])->toMatchArray([
                'name' => $visible->name,
                'slug' => 'acme',
                'portal_enabled' => true,
                'dockerHost' => 'reg.example.test',
                'dockerRepositoryPrefix' => 'kunde/acme/',
            ])
            // The collection group — invisible in the portal — is still listed here,
            // flagged by portal_enabled=false so the Vue layer can label it "Sammlung"
            // rather than silently dropping it from the org-wide token's reach.
            ->and($groups['intern'])->toMatchArray([
                'name' => $collection->name,
                'slug' => 'intern',
                'portal_enabled' => false,
                'dockerHost' => 'reg.example.test',
                'dockerRepositoryPrefix' => 'kunde/intern/',
            ]);
    });

    // A domain-bound group's dockerRepositoryPrefix is empty because ITS OWN domain is the
    // registry root (RegistryUrl::dockerRepositoryPrefix()'s existing rule) — pairing that
    // empty prefix with the top-level shared dockerHost would print a docker login/pull
    // against a host the group is not served from at all. Each entry must carry its own
    // host, read together with its own prefix, never the top-level one.
    it('gives a domain-bound group in the docker list its own host, not the shared instance one', function () {
        $organization = Organization::factory()->create(['slug' => 'kunde']);
        $domainless = Group::factory()->for($organization)->create(['slug' => 'acme']);
        $domainBound = Group::factory()->for($organization)->create(['slug' => 'images']);
        Domain::factory()->for($domainBound)->create(['hostname' => 'images.acme.test']);

        $snips = app(SetupSnippetBuilder::class)->forOrganization($organization->fresh());

        $groups = collect($snips['dockerGroups'])->keyBy('slug');
        expect($groups['acme'])->toMatchArray([
            'dockerHost' => 'reg.example.test',
            'dockerRepositoryPrefix' => 'kunde/acme/',
        ])
            ->and($groups['images'])->toMatchArray([
                'dockerHost' => 'images.acme.test',
                'dockerRepositoryPrefix' => '',
            ]);
    });

    it('gates a disabled ecosystem out of the organization snippet set', function () {
        $organization = Organization::factory()->create(['enabled_registry_types' => ['composer', 'python']]);
        Group::factory()->for($organization)->create();

        $snips = app(SetupSnippetBuilder::class)->forOrganization($organization->fresh());

        expect($snips)->toHaveKey('composer')
            ->toHaveKey('auth')
            ->toHaveKey('pip')
            ->not->toHaveKey('npm')
            ->not->toHaveKey('dockerHost')
            ->not->toHaveKey('dockerGroups');
    });

    it('drops every ecosystem section when the organization has no enabled type', function () {
        $organization = Organization::factory()->create(['enabled_registry_types' => []]);
        Group::factory()->for($organization)->create();

        expect(app(SetupSnippetBuilder::class)->forOrganization($organization->fresh()))->toBe([]);
    });
});

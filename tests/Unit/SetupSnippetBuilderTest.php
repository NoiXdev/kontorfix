<?php

use App\Models\Group;
use App\Services\Registry\RegistryUrl;
use App\Services\Registry\SetupSnippetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
});

it('builds composer, auth and npm snippets for a slug-based registry', function () {
    $group = Group::factory()->create(['slug' => 'acme']);
    $snips = (new SetupSnippetBuilder(app(RegistryUrl::class)))->for($group->fresh());

    expect($snips['composer'])->toContain('"type": "composer"')
        ->toContain('https://reg.example.test/r/acme');
    expect($snips['auth'])->toContain('reg.example.test')
        ->toContain('<dein-token>');
    // Scoped, not global: a bare `registry=` line would route every public package
    // through this registry and make each one depend on an upstream proxy.
    expect($snips['npm'])->toContain('@<dein-scope>:registry=https://reg.example.test/r/acme/')
        ->and($snips['npm'])->not->toContain("\nregistry=")
        ->toContain('//reg.example.test/r/acme/:_authToken=<dein-token>');
});

it('builds pip and twine snippets for the Python registry', function () {
    $group = Group::factory()->create(['slug' => 'acme']);
    $snips = (new SetupSnippetBuilder(app(RegistryUrl::class)))->for($group->fresh());

    expect($snips['pip'])
        ->toContain('--index-url')
        // The command line keeps the inline form (pip accepts nothing else there)…
        ->toContain('https://token:<dein-token>@reg.example.test/r/acme/simple/')
        // …but the persistent config must not carry the token: pip.conf gets the bare
        // index URL and the credential goes to ~/.netrc.
        ->toContain("index-url = https://reg.example.test/r/acme/simple/\n")
        ->toContain('machine reg.example.test');
    expect($snips['twine'])
        ->toContain('[distutils]')
        ->toContain('repository = https://reg.example.test/r/acme/')
        ->toContain('username = token');
});

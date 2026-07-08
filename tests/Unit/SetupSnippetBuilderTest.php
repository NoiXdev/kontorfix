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
    expect($snips['npm'])->toContain('registry=https://reg.example.test/r/acme/')
        ->toContain('//reg.example.test/r/acme/:_authToken=<dein-token>');
});

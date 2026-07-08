<?php

use App\Models\Package;
use App\Services\Webhook\RepoUrlMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('normalizes https, ssh and scp git urls to the same key', function () {
    $m = new RepoUrlMatcher;
    $key = 'github.com/acme/demo';
    expect($m->normalize('https://github.com/acme/demo.git'))->toBe($key)
        ->and($m->normalize('ssh://git@github.com/acme/demo.git'))->toBe($key)
        ->and($m->normalize('git@github.com:acme/demo.git'))->toBe($key)
        ->and($m->normalize('https://github.com/acme/demo/'))->toBe($key);
});

it('matches packages by normalized repository url', function () {
    $p = Package::factory()->create(['repository_url' => 'https://github.com/acme/demo.git']);
    Package::factory()->create(['repository_url' => 'https://github.com/other/thing.git']);
    $m = new RepoUrlMatcher;
    expect($m->match('git@github.com:acme/demo.git')->pluck('id')->all())->toBe([$p->id]);
});

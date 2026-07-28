<?php

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\Group;
use App\Models\Upstream;
use Illuminate\Support\Facades\DB;

it('attaches upstreams to a group with a policy and optional auth', function () {
    $group = Group::factory()->create();
    $up = Upstream::factory()->for($group)->create([
        'type' => PackageType::Composer,
        'url' => 'https://repo.packagist.org',
        'policy' => UpstreamPolicy::Proxy,
        'auth_token' => 'secret',
    ]);

    expect($group->upstreams()->first()->is($up))->toBeTrue()
        ->and($up->type)->toBe(PackageType::Composer)
        ->and($up->policy)->toBe(UpstreamPolicy::Proxy)
        ->and($up->auth_token)->toBe('secret');
});

it('records strict-mode allowlisted package names per upstream', function () {
    $up = Upstream::factory()->create(['policy' => UpstreamPolicy::Strict]);
    $up->allowedPackages()->create(['name' => 'symfony/console']);

    expect($up->allowedPackages()->pluck('name')->all())->toContain('symfony/console');
});

it('encrypts the auth token at rest', function () {
    $up = Upstream::factory()->create(['auth_token' => 'plaintext-secret']);

    // The raw value in the DB is encrypted, the model returns plaintext.
    $raw = DB::table('upstreams')->where('id', $up->id)->value('auth_token');
    expect($raw)->not->toBe('plaintext-secret')
        ->and($up->fresh()->auth_token)->toBe('plaintext-secret');
});

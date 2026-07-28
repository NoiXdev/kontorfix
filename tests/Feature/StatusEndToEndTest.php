<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Upstream;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('renders the status page with core checks green and upstream reachability differentiated', function () {
    Http::fake([
        'https://up-ok.example/*' => Http::response('ok', 200),
        'https://up-down.example/*' => fn () => throw new ConnectionException('refused'),
    ]);

    $reachable = Upstream::factory()->create(['url' => 'https://up-ok.example/packages.json']);
    $down = Upstream::factory()->create(['url' => 'https://up-down.example/packages.json']);

    $admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/admin/status')
        ->assertOk()
        ->assertInertia(function ($p) use ($reachable, $down) {
            $checks = collect($p->toArray()['props']['checks']);

            expect($checks->firstWhere('key', 'database')['ok'])->toBeTrue();
            expect($checks->firstWhere('key', 'cache')['ok'])->toBeTrue();
            expect($checks->firstWhere('key', 'storage')['ok'])->toBeTrue();
            expect($checks->has('key'))->toBeFalse(); // just a sanity check: it's a list
            expect($checks->firstWhere('key', 'upstream:'.$reachable->id)['ok'])->toBeTrue();
            expect($checks->firstWhere('key', 'upstream:'.$down->id)['ok'])->toBeFalse();
        });
});

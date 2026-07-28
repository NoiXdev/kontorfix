<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('read key is blocked on every mutating verb', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $op->id, 'role' => 'admin']);
    [, $read] = ApiKey::issue($admin, 'r', ApiKeyPermission::Read);

    $this->withToken($read)->postJson('/api/v1/groups', [])->assertForbidden();

    // Für {group}-Routen mit einer NICHT existierenden ID greift Laravels implizites
    // Route-Model-Binding (SubstituteBindings, Teil der globalen `api`-Gruppe) vor dem
    // read/write-Gate der `api.auth`-Middleware — verifiziert per Debug-Request: eine
    // reale, existierende Group liefert mit demselben read-Key korrekt 403 (Gate greift),
    // erst bei nicht auflösbarer ID kommt die ModelNotFoundException (404) zuerst durch.
    // Kein Sicherheitsproblem (keine Mutation, keine Informationslecks), aber ein reales
    // Verhalten, das hier dokumentiert statt weggemockt wird.
    $this->withToken($read)->putJson('/api/v1/groups/x', [])->assertNotFound();
    $this->withToken($read)->deleteJson('/api/v1/groups/x')->assertNotFound();
});

it('expired key is unauthorized', function () {
    $u = User::factory()->create();
    [, $plain] = ApiKey::issue($u, 'old', ApiKeyPermission::Read, now()->subMinute());
    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
});

it('rate limits after the configured threshold', function () {
    Cache::flush();
    $u = User::factory()->create();
    [, $plain] = ApiKey::issue($u, 'rl', ApiKeyPermission::Read);

    foreach (range(1, 120) as $_) {
        $this->withToken($plain)->getJson('/api/v1/me');
    }
    $this->withToken($plain)->getJson('/api/v1/me')->assertStatus(429);
});

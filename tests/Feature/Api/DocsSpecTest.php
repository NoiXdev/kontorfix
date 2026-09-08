<?php

use App\Models\Organization;
use App\Models\User;

it('serves the openapi document to an operator admin and lists api paths', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $op->id, 'role' => 'admin']);

    $res = $this->actingAs($admin)->get('/docs/api.json')->assertOk();

    expect($res->json('paths'))->toHaveKey('/api/v1/me');
});

it('denies non-operators access to the api docs', function () {
    $customer = Organization::factory()->create(['is_operator' => false]);
    $member = User::factory()->create(['organization_id' => $customer->id, 'role' => 'member']);

    $this->actingAs($member)->get('/docs/api.json')->assertForbidden();
});

it('gives every documented operation a non-empty summary', function () {
    // Regression guard: Scramble falls back to a raw method-name-derived title
    // (e.g. "packagesIndex") when a route handler has no docblock summary, which
    // is meaningless to a German-speaking operator browsing /docs/api. Every
    // operation the generator emits must carry a real summary.
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $op->id, 'role' => 'admin']);

    $spec = $this->actingAs($admin)->get('/docs/api.json')->assertOk()->json();

    $httpMethods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'];

    $missing = [];
    foreach ($spec['paths'] ?? [] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (! in_array($method, $httpMethods, true) || ! is_array($operation)) {
                continue;
            }

            if (trim((string) ($operation['summary'] ?? '')) === '') {
                $missing[] = strtoupper($method).' '.$path;
            }
        }
    }

    expect($missing)->toBe([]);
});

<?php

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * `min((int) $request->query('per_page', 25), 100)` bounded the top of the range and
 * nothing else. `per_page=0` reached `LengthAwarePaginator` as a zero page size and was
 * an unhandled 500; `per_page=-1` — and every non-numeric value, which casts to 0 —
 * removed the `LIMIT` from the query entirely and returned the whole table in one
 * response. On a collection endpoint that is a denial-of-service primitive, reachable by
 * any key holder, not merely an error.
 */
beforeEach(function () {
    $org = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create([
        'organization_id' => $org->id,
        'role' => UserRole::Admin,
        'is_super_admin' => true,
    ]);
    [, $this->token] = ApiKey::issue($this->admin, 'k', ApiKeyPermission::Write);

    // Three rows per collection, so a page size of one is visibly a page and not the
    // whole table.
    Package::factory()->count(3)->create();
    Group::factory()->count(3)->for($org)->create();
    User::factory()->count(2)->create();
    Organization::factory()->count(2)->create();
    RegistryToken::factory()->count(3)->create(['organization_id' => $org->id]);
});

$collections = [
    'packages' => '/api/v1/packages',
    'groups' => '/api/v1/groups',
    'users' => '/api/v1/users',
    'organizations' => '/api/v1/organizations',
    'registry-tokens' => '/api/v1/registry-tokens',
];

foreach ($collections as $name => $uri) {
    it("clamps an out-of-range per_page on {$name} instead of failing or unbounding it", function () use ($uri) {
        // Reachability anchor: the same URI with a well-formed per_page is answered by
        // the controller with a paginated body, so the cases below exercise the clamp
        // and not the throttle, the bearer check or the super gate.
        $this->withToken($this->token)->getJson($uri.'?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonCount(2, 'data');

        // Zero was a 500.
        $this->withToken($this->token)->getJson($uri.'?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data');

        // Negative removed the LIMIT.
        $this->withToken($this->token)->getJson($uri.'?per_page=-1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data');

        // Non-numeric casts to zero and took the same path.
        $this->withToken($this->token)->getJson($uri.'?per_page=all')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data');

        // The upper bound is unchanged.
        $this->withToken($this->token)->getJson($uri.'?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    });
}

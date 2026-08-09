<?php

// A07 (carried partial C10) — `OidcUserResolver` matches on `lower(email)`, so the
// application treats an address as one identity whatever its case. The column does not:
// `users.email` carries a plain case-sensitive unique constraint, so `Root@firma.de` and
// `root@firma.de` can both exist and the resolver picks between them with an unordered
// `first()`. An earlier round matched case-insensitively and declined the index because a
// pre-existing pair would fail the migration and take a deploy with it.
//
// Three parts, so the index is real without the deploy risk: the index itself (unique where
// it can be, non-unique with a named warning where it cannot, re-runnable once resolved),
// an application check on every write path so the set can only shrink, and a deterministic
// order for the resolver on an instance still carrying a pair.

use App\Enums\UserRole;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\Oidc\OidcUserResolver;
use App\Services\Users\EmailUniquenessIndex;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function caseVariantRow(string $email, ?string $createdAt = null): User
{
    // Straight to the table: the point is a row the application would now refuse.
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update([
        'email' => $email,
        'created_at' => $createdAt ?? now()->toDateTimeString(),
    ]);

    return $user->fresh();
}

it('has the unique index on lower(email) after migrating', function () {
    expect(app(EmailUniquenessIndex::class)->isEnforced())->toBeTrue();
});

it('refuses a case-variant duplicate at the database level', function () {
    $existing = User::factory()->create(['email' => 'root@firma.de']);

    expect(fn () => DB::table('users')->insert([
        'id' => (string) Str::uuid(),
        'name' => 'Lookalike',
        'email' => 'Root@firma.de',
        'password' => 'x',
        'organization_id' => $existing->organization_id,
        'role' => UserRole::Member->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('still admits a different address — the anchor for the case above', function () {
    // Same table, same insert shape: so the refusal above is the functional index and not
    // the plain unique constraint or a not-null column.
    $existing = User::factory()->create(['email' => 'root@firma.de']);

    DB::table('users')->insert([
        'id' => (string) Str::uuid(),
        'name' => 'Someone else',
        'email' => 'other@firma.de',
        'password' => 'x',
        'organization_id' => $existing->organization_id,
        'role' => UserRole::Member->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(User::where('email', 'other@firma.de')->exists())->toBeTrue();
});

it('leaves robot accounts without an address alone', function () {
    // The index is partial for a reason: `users.email` is nullable and every robot has null.
    User::factory()->count(3)->create(['email' => null]);

    expect(User::whereNull('email')->count())->toBe(3);
});

it('refuses a case-variant address through the admin console', function () {
    $org = Organization::factory()->create(['is_operator' => true]);
    $superAdmin = User::factory()->for($org)->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
    User::factory()->for($org)->create(['email' => 'root@firma.de']);

    $this->actingAs($superAdmin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/admin/users', [
            'name' => 'Lookalike',
            'email' => 'Root@firma.de',
            'organization_id' => $org->id,
            'role' => 'member',
        ])->assertSessionHasErrors('email');

    expect(User::whereRaw('lower(email) = ?', ['root@firma.de'])->count())->toBe(1);
});

it('refuses a lower-case address a legacy mixed-case row already holds', function () {
    // The case the plain `unique:users,email` rule cannot see, and the reason the check is
    // not just input normalisation: the stored row is the mixed-case one. An instance whose
    // index had to fall back still needs this refusal.
    $org = Organization::factory()->create(['is_operator' => true]);
    $superAdmin = User::factory()->for($org)->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
    caseVariantRow('Root@firma.de');

    $this->actingAs($superAdmin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/admin/users', [
            'name' => 'Lookalike',
            'email' => 'root@firma.de',
            'organization_id' => $org->id,
            'role' => 'member',
        ])->assertSessionHasErrors('email');
});

it('stores an address the console was given in mixed case in lower case', function () {
    $org = Organization::factory()->create(['is_operator' => true]);
    $superAdmin = User::factory()->for($org)->create(['role' => UserRole::Admin, 'is_super_admin' => true]);

    $this->actingAs($superAdmin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/admin/users', [
            'name' => 'Neu',
            'email' => 'Neu@Firma.DE',
            'organization_id' => $org->id,
            'role' => 'member',
        ])->assertSessionHasNoErrors();

    expect(User::where('name', 'Neu')->sole()->email)->toBe('neu@firma.de');
});

it('falls back to a non-unique index instead of failing a deploy that has collisions', function () {
    $index = app(EmailUniquenessIndex::class);
    DB::statement('DROP INDEX IF EXISTS '.EmailUniquenessIndex::UNIQUE_INDEX);

    $org = Organization::factory()->create();
    caseVariantRow('root@firma.de');
    User::factory()->for($org)->create();
    caseVariantRow('Root@firma.de');

    expect($index->collisions())->toBe(['root@firma.de' => 2])
        ->and($index->install())->toBeFalse()
        ->and($index->isEnforced())->toBeFalse();

    // …and the upgrade lands as soon as the operator resolves them.
    User::whereRaw('email = ?', ['Root@firma.de'])->delete();

    expect($index->install())->toBeTrue()
        ->and($index->isEnforced())->toBeTrue();
});

it('links the account that held the address first when a pair survives', function () {
    DB::statement('DROP INDEX IF EXISTS '.EmailUniquenessIndex::UNIQUE_INDEX);

    $org = Organization::factory()->create();
    $older = caseVariantRow('root@firma.de', now()->subYear()->toDateTimeString());
    $newer = caseVariantRow('Root@firma.de', now()->toDateTimeString());

    $provider = OidcProvider::factory()->create(['allow_registration' => false]);

    $resolved = (new OidcUserResolver)->resolve($provider, [
        'sub' => 'idp-subject-1',
        'email' => 'ROOT@firma.de',
        'email_verified' => true,
    ]);

    expect($resolved->id)->toBe($older->id)
        ->and($resolved->id)->not->toBe($newer->id);
});

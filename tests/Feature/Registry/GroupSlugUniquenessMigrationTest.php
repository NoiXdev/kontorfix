<?php

use App\Models\Group;
use App\Models\Organization;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

function runScopeGroupSlugMigration(): object
{
    return require database_path('migrations/2026_09_03_100000_scope_group_slug_to_organization.php');
}

it('refuses to scope the slugs while one names both an organization and a registry', function () {
    // The legacy /r/{slug} redirect is a one-segment route registered after the canonical
    // /r/{orgSlug}/{groupSlug}, and Laravel matches the first route that fits. With an
    // organization called `acme`, /r/acme/foo would therefore resolve to registry `foo` of
    // organization `acme` instead of the legacy registry `acme` — a wrong answer, which is
    // worse than an error. The refusal runs before any schema change, so nothing here has
    // to restore the pre-migration index.
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->create(['slug' => 'acme']);

    // Asserted on the message, not on RuntimeException: Illuminate's QueryException extends
    // PDOException extends RuntimeException, and a raw unique violation names the very same
    // identifiers, so a class-only assertion could not tell a clean refusal from the
    // failure it exists to prevent.
    $message = null;
    try {
        runScopeGroupSlugMigration()->up();
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('these slugs name both an organization and a registry')
        // Both sides have to be named, or the operator cannot act on the message.
        ->and($message)->toContain($org->id)
        ->and($message)->toContain($group->id);
});

it('swaps the instance-wide slug index for a per-organization one', function () {
    // RefreshDatabase has already applied the migration, so put the schema back to its
    // pre-migration state — one global unique(slug) — and let up() do the swap for real.
    Schema::table('groups', function (Blueprint $table) {
        $table->dropUnique(['organization_id', 'slug']);
        $table->unique(['slug']);
    });

    $first = Group::factory()->create(['slug' => 'packages']);

    runScopeGroupSlugMigration()->up();

    // Under the restored pre-migration index this second row was impossible; it is the
    // swap that makes it insertable.
    $second = Group::factory()->create(['slug' => 'packages']);

    expect($second->exists)->toBeTrue()
        ->and($second->organization_id)->not->toBe($first->organization_id);

    // …and the slug is still taken inside the organization that already holds it. Asserted
    // last: a unique violation aborts the surrounding Postgres transaction, so nothing can
    // query afterwards.
    expect(fn () => Group::factory()->for($first->organization)->create(['slug' => 'packages']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses to roll back while two organizations hold the same registry slug', function () {
    // down() restores the old instance-wide unique(slug), which cannot hold once two
    // organizations legitimately share a slug — the whole point of up(). Refused with the
    // slugs named, rather than a bare unique violation part-way through the rollback.
    Group::factory()->create(['slug' => 'packages']);
    Group::factory()->create(['slug' => 'packages']);

    $message = null;
    try {
        runScopeGroupSlugMigration()->down();
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('Cannot restore instance-wide registry slug uniqueness')
        ->and($message)->toContain('packages');

    // The refusal happens before any schema change, so the org-scoped index is still there.
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create(['slug' => 'shared']);
    expect(fn () => Group::factory()->for($org)->create(['slug' => 'shared']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('rolls back to instance-wide uniqueness when no slug is shared', function () {
    $group = Group::factory()->create(['slug' => 'packages']);

    runScopeGroupSlugMigration()->down();

    // The rollback leaves the rows alone; it is only the index that changes back.
    expect($group->fresh()->slug)->toBe('packages');

    expect(fn () => Group::factory()->create(['slug' => 'packages']))
        ->toThrow(UniqueConstraintViolationException::class);
});

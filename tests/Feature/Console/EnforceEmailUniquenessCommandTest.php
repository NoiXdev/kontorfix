<?php

// `php artisan users:enforce-email-uniqueness` is the operator's remedy for the one state
// the email-uniqueness migration is allowed to leave behind: when case-variant duplicates
// already existed, it installs a NON-unique index rather than failing the deploy, and this
// command is what upgrades it once the duplicates are resolved. Without the command that
// degraded state is permanent — "deploy-safe" would just mean "quietly weaker forever".
//
// It matters because OidcUserResolver matches on `lower(email)` while the column's own
// constraint is case-sensitive, so a surviving pair is an identity-confusion state the
// resolver has to pick between. The command had no test at all: the tool for repairing a
// security-relevant state had never been proven to work.

use App\Models\User;
use App\Services\Users\EmailUniquenessIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** A row written straight to the table, because the application would now refuse it. */
function collidingRow(string $email): User
{
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['email' => $email]);

    return $user->fresh();
}

it('reports success and leaves the unique index in force when nothing collides', function () {
    User::factory()->create(['email' => 'anna@firma.de']);

    $this->artisan('users:enforce-email-uniqueness')
        ->expectsOutputToContain('Eindeutiger Index auf lower(users.email) ist aktiv.')
        ->assertSuccessful();

    expect(app(EmailUniquenessIndex::class)->isEnforced())->toBeTrue();
});

it('fails and names every colliding address rather than installing a unique index over them', function () {
    // The index has to be dropped first: it is enforced by default, so a colliding pair
    // cannot exist while it stands — which is exactly the pre-upgrade state this command
    // was written for.
    DB::statement('DROP INDEX IF EXISTS '.EmailUniquenessIndex::UNIQUE_INDEX);
    collidingRow('root@firma.de');
    collidingRow('Root@firma.de');

    // Asserted against the captured output rather than through chained
    // expectsOutputToContain(): the command genuinely prints "root@firma.de (2 Konten)",
    // but Laravel's chained matcher does not see the second substring on the same line.
    // This also pins the useful part — the operator is told WHICH address and HOW MANY
    // accounts, because that is what they need in order to go and merge them.
    $exit = Artisan::call('users:enforce-email-uniqueness');
    $output = Artisan::output();

    expect($exit)->toBe(Command::FAILURE)
        ->and($output)->toContain('root@firma.de (2 Konten)')
        ->and($output)->toContain('erneut ausführen');

    // Degraded, not absent: the application still gets an index to match on, it just
    // cannot be the unique one yet.
    expect(app(EmailUniquenessIndex::class)->isEnforced())->toBeFalse();
});

it('upgrades the index once the operator has resolved the collision', function () {
    DB::statement('DROP INDEX IF EXISTS '.EmailUniquenessIndex::UNIQUE_INDEX);
    collidingRow('root@firma.de');
    $newer = collidingRow('Root@firma.de');

    $this->artisan('users:enforce-email-uniqueness')->assertFailed();

    $newer->delete();

    $this->artisan('users:enforce-email-uniqueness')->assertSuccessful();

    expect(app(EmailUniquenessIndex::class)->isEnforced())->toBeTrue();
});

it('is idempotent — running it again on a healthy instance changes nothing', function () {
    User::factory()->create(['email' => 'anna@firma.de']);

    $this->artisan('users:enforce-email-uniqueness')->assertSuccessful();
    $this->artisan('users:enforce-email-uniqueness')->assertSuccessful();

    expect(app(EmailUniquenessIndex::class)->isEnforced())->toBeTrue();
});

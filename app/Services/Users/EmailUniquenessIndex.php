<?php

namespace App\Services\Users;

use Illuminate\Support\Facades\DB;

/**
 * The database half of "an email address identifies one account, whatever its case".
 *
 * `OidcUserResolver` matches on `lower(email)`, so the application already treats the
 * address case-insensitively — but `users.email` carries a plain case-sensitive unique
 * constraint, so `Root@firma.de` and `root@firma.de` can both exist and the resolver then
 * picks between them with an unordered `first()`. An earlier round declined the functional
 * index because a pre-existing case-variant pair would make the migration fail, taking a
 * deploy down with it.
 *
 * That is worth avoiding, and it does not require giving up the index:
 *
 *  - with no collisions, the UNIQUE functional index goes on and the state is impossible
 *    from then on, whatever writes the column;
 *  - with collisions, the same expression is indexed NON-uniquely, the operator is told
 *    exactly which addresses are involved (log + operator health page), and the deploy
 *    proceeds. Nothing is deleted or merged — picking which of two real accounts survives
 *    is not a migration's decision.
 *
 * `users:enforce-email-uniqueness` re-runs the upgrade once those are resolved, so the
 * degraded state is a step on the way rather than a permanent downgrade. Meanwhile the
 * application check (App\Rules\UniqueEmail on every write path) keeps new collisions out,
 * so the set can only shrink.
 */
class EmailUniquenessIndex
{
    public const UNIQUE_INDEX = 'users_email_lower_unique';

    public const FALLBACK_INDEX = 'users_email_lower_idx';

    /**
     * Addresses held by more than one account once case is ignored, with their counts.
     *
     * @return array<string, int>
     */
    public function collisions(): array
    {
        /** @var list<object{email: string, total: int}> $rows */
        $rows = DB::table('users')
            ->selectRaw('lower(email) as email, count(*) as total')
            ->whereNotNull('email')
            ->groupByRaw('lower(email)')
            ->havingRaw('count(*) > 1')
            ->orderByRaw('lower(email)')
            ->get()
            ->all();

        $collisions = [];
        foreach ($rows as $row) {
            $collisions[(string) $row->email] = (int) $row->total;
        }

        return $collisions;
    }

    /**
     * Whether the ENFORCING index is in place — the definition is checked, not the name,
     * because an index that merely exists under that name enforces nothing.
     */
    public function isEnforced(): bool
    {
        $definition = DB::table('pg_indexes')
            ->where('tablename', 'users')
            ->where('indexname', self::UNIQUE_INDEX)
            ->value('indexdef');

        return is_string($definition) && str_contains(strtoupper($definition), 'CREATE UNIQUE INDEX');
    }

    /**
     * Installs the strongest index the data allows, and reports whether that is the
     * unique one. Idempotent, so the command may be re-run at any time.
     */
    public function install(): bool
    {
        if ($this->collisions() !== []) {
            DB::statement('DROP INDEX IF EXISTS '.self::UNIQUE_INDEX);
            DB::statement('CREATE INDEX IF NOT EXISTS '.self::FALLBACK_INDEX.' ON users (lower(email)) WHERE email IS NOT NULL');

            return false;
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::UNIQUE_INDEX.' ON users (lower(email)) WHERE email IS NOT NULL');
        DB::statement('DROP INDEX IF EXISTS '.self::FALLBACK_INDEX);

        return true;
    }
}

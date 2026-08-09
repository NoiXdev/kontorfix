<?php

use App\Services\Users\EmailUniquenessIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `users.email` has a case-SENSITIVE unique constraint while `OidcUserResolver` matches on
 * `lower(email)`, so two accounts differing only in case can exist and the resolver then
 * chooses between them. See EmailUniquenessIndex for why this never fails the deploy and
 * what happens when collisions are already present.
 */
return new class extends Migration
{
    public function up(): void
    {
        $index = new EmailUniquenessIndex;
        $collisions = $index->collisions();

        if (! $index->install()) {
            Log::warning('users.email is not case-insensitively unique; installed a non-unique index instead.', [
                'collisions' => $collisions,
                'resolve_with' => 'php artisan users:enforce-email-uniqueness',
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.EmailUniquenessIndex::UNIQUE_INDEX);
        DB::statement('DROP INDEX IF EXISTS '.EmailUniquenessIndex::FALLBACK_INDEX);
    }
};

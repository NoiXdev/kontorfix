<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Robot (service) accounts have no mailbox, so they carry no email address. The
     * unique index stays — Postgres treats NULLs as distinct, so multiple robots
     * without an email do not collide.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
    }

    public function down(): void
    {
        // Only reversible when no NULLs exist; a fresh placeholder keeps the rollback
        // from failing on any robot rows created in the meantime.
        DB::statement("UPDATE users SET email = concat('robot-', id, '@invalid.local') WHERE email IS NULL");
        DB::statement('ALTER TABLE users ALTER COLUMN email SET NOT NULL');
    }
};

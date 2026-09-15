<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Revoking an API key used to DELETE the row, so a withdrawn credential was
     * indistinguishable from one that never existed — no record that it had been issued,
     * and nothing for its owner to see. `registry_tokens` has carried `revoked_at` for
     * exactly this reason since it shipped; this brings API keys into line.
     *
     * Additive and nullable: every existing key is live, which is what null already means.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });
    }
};

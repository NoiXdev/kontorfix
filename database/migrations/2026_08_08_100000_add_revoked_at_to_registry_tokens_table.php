<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deprovisioning marks a token instead of deleting it. A dropped row cannot be
     * inspected after the fact and cannot be undone if the action was a mistake, which
     * for an operator-triggered lifecycle rule is the wrong default.
     */
    public function up(): void
    {
        Schema::table('registry_tokens', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('registry_tokens', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });
    }
};

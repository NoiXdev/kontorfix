<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional access token for syncing a private git repository (e.g. a GitHub PAT).
     * Stored encrypted at the application layer; text column to fit the ciphertext.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->text('repository_token')->nullable()->after('repository_url');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('repository_token');
        });
    }
};

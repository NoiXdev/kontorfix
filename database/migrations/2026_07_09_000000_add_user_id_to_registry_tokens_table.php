<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registry_tokens', function (Blueprint $table) {
            $table->foreignUuid('user_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registry_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_operator')->default(false); // Betreiber-Org
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->nullable()->constrained();
            $table->string('role')->default('member');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn('role');
        });

        Schema::dropIfExists('organizations');
    }
};

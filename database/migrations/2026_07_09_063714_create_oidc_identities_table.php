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
        Schema::create('oidc_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('oidc_provider_id')->constrained('oidc_providers')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['oidc_provider_id', 'subject']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oidc_identities');
    }
};

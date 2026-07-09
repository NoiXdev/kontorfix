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
        Schema::create('oidc_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('client_id');
            $table->text('client_secret');
            $table->string('issuer')->nullable();
            $table->string('authorization_endpoint')->nullable();
            $table->string('token_endpoint')->nullable();
            $table->string('userinfo_endpoint')->nullable();
            $table->string('jwks_uri')->nullable();
            $table->string('scopes')->default('openid email profile');
            $table->boolean('enabled')->default(false);
            $table->boolean('allow_registration')->default(false);
            $table->foreignUuid('default_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('default_role')->default('member');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oidc_providers');
    }
};

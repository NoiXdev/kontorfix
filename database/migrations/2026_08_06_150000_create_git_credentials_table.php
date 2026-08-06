<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable, organization-scoped git access credentials (tokens) that can be assigned
     * to packages for syncing private repositories. The token is encrypted at the
     * application layer (text column to fit the ciphertext).
     */
    public function up(): void
    {
        Schema::create('git_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('provider')->default('github');
            $table->string('username')->nullable();
            $table->text('token');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('organization_id');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->foreignUuid('git_credential_id')->nullable()->after('repository_token')
                ->constrained('git_credentials')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('git_credential_id');
        });
        Schema::dropIfExists('git_credentials');
    }
};

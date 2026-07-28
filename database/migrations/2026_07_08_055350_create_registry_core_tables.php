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
        Schema::create('packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');                    // PackageType
            $table->string('name');                    // vendor/name or @scope/name
            $table->string('description')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('sync_status')->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['type', 'name']);
        });

        Schema::create('package_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('package_id')->constrained()->cascadeOnDelete();
            $table->string('version');                 // normalized, e.g. 1.2.0.0
            $table->string('version_pretty');          // e.g. v1.2.0
            $table->string('source_reference');        // commit sha / tag
            $table->jsonb('metadata');                 // composer.json of the tag
            $table->string('dist_path')->nullable();   // path on artifacts disk
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['package_id', 'version']);
            $table->index(['package_id', 'released_at']);
        });

        Schema::create('groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('public')->default(false);
            $table->timestamps();
        });

        Schema::create('group_package', function (Blueprint $table) {
            $table->foreignUuid('group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('package_id')->constrained()->cascadeOnDelete();
            $table->string('version_constraint')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->primary(['group_id', 'package_id']);
            $table->index('package_id');
        });

        Schema::create('domains', function (Blueprint $table) {   // used from v0.2
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->constrained()->cascadeOnDelete();
            $table->string('hostname')->unique();
            $table->timestamps();
        });

        Schema::create('registry_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained()->cascadeOnDelete(); // null = all groups of the org
            $table->string('name');
            $table->string('token_hash', 64)->unique();  // sha256
            $table->string('ability')->default('read');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registry_tokens');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('group_package');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('package_versions');
        Schema::dropIfExists('packages');
    }
};

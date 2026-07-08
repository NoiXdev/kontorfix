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
        Schema::create('upstreams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('group_id')->constrained()->cascadeOnDelete();
            $table->string('type');                 // PackageType composer|npm
            $table->string('url');                  // Upstream-Basis-URL
            $table->string('policy')->default('proxy');
            $table->text('auth_token')->nullable(); // encrypted cast
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('upstream_allowed_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('upstream_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['upstream_id', 'name']);
        });

        Schema::create('upstream_metadata_cache', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('upstream_id')->constrained()->cascadeOnDelete();
            $table->string('package_name');
            $table->jsonb('payload');
            $table->timestamp('fetched_at');
            $table->timestamps();
            $table->unique(['upstream_id', 'package_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('upstream_metadata_cache');
        Schema::dropIfExists('upstream_allowed_packages');
        Schema::dropIfExists('upstreams');
    }
};

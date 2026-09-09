<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable, organization-scoped foreign registries (Composer/npm/PyPI) a package can
     * mirror from — the org-level counterpart to the per-package git repository a
     * git-sourced package already points at. The auth token is encrypted at the
     * application layer (text column to fit the ciphertext).
     */
    public function up(): void
    {
        Schema::create('mirror_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('url');
            $table->text('auth_token')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('packages', function (Blueprint $table) {
            // nullOnDelete, not cascade: a mirror source is a reusable pointer at a foreign
            // registry, and deleting one must not take with it the packages a maintainer
            // already built from it — only the link is cleared, same policy as
            // git_credential_id above it.
            $table->foreignUuid('mirror_source_id')->nullable()->after('git_credential_id')
                ->constrained('mirror_sources')->nullOnDelete();
            $table->string('mirror_name')->nullable()->after('mirror_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mirror_source_id');
            $table->dropColumn('mirror_name');
        });

        Schema::dropIfExists('mirror_sources');
    }
};

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
        Schema::table('packages', function (Blueprint $table) {
            $table->jsonb('dist_tags')->nullable();
        });

        Schema::table('package_versions', function (Blueprint $table) {
            $table->string('source_reference')->nullable()->change();
            $table->string('dist_shasum')->nullable();
            $table->string('dist_integrity')->nullable();
            $table->string('dist_tarball_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('dist_tags');
        });

        Schema::table('package_versions', function (Blueprint $table) {
            $table->dropColumn(['dist_shasum', 'dist_integrity', 'dist_tarball_name']);
        });
    }
};

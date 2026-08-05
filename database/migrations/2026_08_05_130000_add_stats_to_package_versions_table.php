<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-version usage stats: how often the dist was downloaded and how much storage
     * the built dist occupies. Aggregated up to package and registry level in the UI.
     */
    public function up(): void
    {
        Schema::table('package_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('download_count')->default(0)->after('dist_path');
            $table->unsignedBigInteger('dist_size')->nullable()->after('download_count');
        });
    }

    public function down(): void
    {
        Schema::table('package_versions', function (Blueprint $table) {
            $table->dropColumn(['download_count', 'dist_size']);
        });
    }
};

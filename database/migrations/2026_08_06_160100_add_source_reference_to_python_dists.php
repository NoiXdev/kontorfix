<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('python_dists', function (Blueprint $table): void {
            // Set for git-mirror sdists (the commit the archive was built from) so a
            // re-sync can detect force-pushes and rebuild; null for uploaded files.
            $table->string('source_reference', 64)->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('python_dists', function (Blueprint $table): void {
            $table->dropColumn('source_reference');
        });
    }
};

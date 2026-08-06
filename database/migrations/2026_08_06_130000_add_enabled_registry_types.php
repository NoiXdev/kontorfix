<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which registry types (composer/npm/python) are offered. The system setting is the
     * instance-wide ceiling; an organization may restrict further within it. A NULL org
     * value means "inherit the global set".
     */
    public function up(): void
    {
        $default = json_encode(['composer', 'npm', 'python']);

        Schema::table('system_settings', function (Blueprint $table) use ($default) {
            $table->json('enabled_registry_types')->default($default);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->json('enabled_registry_types')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn('enabled_registry_types');
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('enabled_registry_types');
        });
    }
};

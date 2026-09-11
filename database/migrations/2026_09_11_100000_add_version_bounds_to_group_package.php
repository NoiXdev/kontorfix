<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_package', function (Blueprint $table) {
            // Replaces `version_constraint` in function (that column is written nowhere and
            // read nowhere — dead, and left in place rather than dropped or reused). Null on
            // both means unlimited: an assignment carries every version of the package, which
            // is exactly how every existing row behaves today and must go on behaving.
            $table->string('version_min')->nullable()->after('version_constraint');
            $table->string('version_max')->nullable()->after('version_min');
        });
    }

    public function down(): void
    {
        Schema::table('group_package', function (Blueprint $table) {
            $table->dropColumn(['version_min', 'version_max']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When false, the group is a collection-only container: not shown as a registry in
     * the portal, its packages meant to be composed into other groups of the same org.
     * Defaults to true so existing groups stay visible.
     */
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('portal_enabled')->default(true)->after('public');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('portal_enabled');
        });
    }
};

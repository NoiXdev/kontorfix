<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            // 'publish' (npm/twine upload) or 'git' (mirror a repository's tags).
            $table->string('source_mode', 20)->default('publish')->after('type');
        });

        // Composer packages are always git-sourced; reflect that in the column so the
        // stored intent is truthful for existing rows.
        DB::table('packages')->where('type', 'composer')->update(['source_mode' => 'git']);
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn('source_mode');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Sanitized HTML, not markdown: the render happens once per sync in a queue
            // worker rather than once per request in the web tier.
            $table->text('readme_html')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('readme_html');
        });
    }
};

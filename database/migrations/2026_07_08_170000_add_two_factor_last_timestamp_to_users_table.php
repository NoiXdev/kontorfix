<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Last successfully used TOTP time step (unix/30). Codes <= this
            // value are rejected → the same code cannot be reused within the window.
            $table->unsignedBigInteger('two_factor_last_timestamp')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_last_timestamp');
        });
    }
};

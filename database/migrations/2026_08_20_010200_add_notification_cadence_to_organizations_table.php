<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            // 'hourly' | 'daily' | 'off'. A plain string rather than a database enum, so a
            // later value does not need a migration on a live table.
            $table->string('notification_cadence', 16)->default('hourly');
            $table->timestamp('last_digest_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['notification_cadence', 'last_digest_sent_at']);
        });
    }
};

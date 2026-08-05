<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-managed incoming webhook endpoints. Each row is one configured git-host
     * hook with its own secret and its own URL (/webhooks/{provider}/{id}), so an
     * operator can register e.g. several GitHub hosts/repos independently instead of
     * sharing one global secret.
     */
    public function up(): void
    {
        Schema::create('incoming_webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('provider'); // github | gitlab | gitea | bitbucket
            $table->text('secret');     // encrypted at the model layer
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_webhooks');
    }
};

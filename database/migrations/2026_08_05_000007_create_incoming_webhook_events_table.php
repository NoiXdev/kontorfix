<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail of received incoming webhooks — kept for debugging "why didn't my
     * push trigger a sync?". Every delivery is logged, valid or not, with its payload.
     * Retention is capped to the most recent rows (see IncomingWebhookEvent::prune()).
     */
    public function up(): void
    {
        Schema::create('incoming_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('incoming_webhook_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('repo_url')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->unsignedInteger('matched_packages')->default(0);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('ip', 45)->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_webhook_events');
    }
};

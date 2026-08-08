<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The audit ring is pruned per hook and per verification outcome, so the prune reads
     * exactly this key on every delivery. Additive: an index only.
     */
    public function up(): void
    {
        Schema::table('incoming_webhook_events', function (Blueprint $table) {
            $table->index(
                ['incoming_webhook_id', 'signature_valid', 'created_at'],
                'incoming_webhook_events_ring_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('incoming_webhook_events', function (Blueprint $table) {
            $table->dropIndex('incoming_webhook_events_ring_index');
        });
    }
};

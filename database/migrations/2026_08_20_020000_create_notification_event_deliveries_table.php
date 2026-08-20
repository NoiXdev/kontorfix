<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_event_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_event_id')->constrained('notification_events')->cascadeOnDelete();
            $table->foreignUuid('notification_recipient_id')->constrained('notification_recipients')->cascadeOnDelete();
            $table->timestamp('delivered_at');

            // Delivery is a fact about this (event, recipient) pair: the constraint is what
            // makes a double-send structurally impossible rather than merely unlikely.
            $table->unique(['notification_event_id', 'notification_recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_event_deliveries');
    }
};

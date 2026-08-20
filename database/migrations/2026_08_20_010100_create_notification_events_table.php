<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            // Denormalised on purpose: a package deleted after it failed should still read
            // as a name in the digest rather than as a dangling id.
            $table->string('subject_label');
            $table->text('summary');
            $table->timestamp('occurred_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            // The digest's hot query: unreported rows for one organization.
            $table->index(['organization_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            // Same shape as `webhooks.events`: the subscription lives on the subscriber.
            $table->json('events')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }
};

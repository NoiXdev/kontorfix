<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            // Activity's own PK stays the package default (auto-increment bigint).
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            // UUID morphs: every subject/causer in this app (Organization, Group,
            // Package, User) has a UUID primary key, so the default bigint morphs
            // from Spatie's stub would reject the ids.
            $table->nullableUuidMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableUuidMorphs('causer', 'causer');
            $table->jsonb('attribute_changes')->nullable();
            $table->jsonb('properties')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};

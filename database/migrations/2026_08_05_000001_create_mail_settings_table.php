<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // log | smtp | postal — 'log' keeps a fresh install from silently
            // failing to deliver against an unconfigured backend.
            $table->string('mailer')->default('log');
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();

            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->nullable();
            $table->string('smtp_username')->nullable();
            // Encrypted at the model layer, so the column must hold ciphertext.
            $table->text('smtp_password')->nullable();
            $table->string('smtp_encryption')->nullable();

            $table->string('postal_domain')->nullable();
            $table->text('postal_key')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};

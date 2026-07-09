<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('storage_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('driver')->default('local');
            $table->string('key')->nullable();
            $table->text('secret')->nullable();
            $table->string('region')->nullable();
            $table->string('bucket')->nullable();
            $table->string('endpoint')->nullable();
            $table->string('url')->nullable();
            $table->boolean('use_path_style')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('storage_settings');
    }
};

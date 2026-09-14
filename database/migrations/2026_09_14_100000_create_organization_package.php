<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One licence per (organization, package): "this customer is licensed for this shared
     * package, in this version window, until this date".
     *
     * Deliberately NOT `group_package` with a nullable `group_id`, which is the idiom
     * `registry_tokens` uses for org-wide scope. That table's primary key is
     * (group_id, package_id) and every existing query joins through it, so a null there
     * would need a new key plus an audit of every one of those queries — where a single
     * missed one would silently read a licence row as a registry assignment and serve a
     * package into a registry nobody assigned it to.
     *
     * No `version_constraint` column: the one on `group_package` is the older Composer-side
     * construct and is written nowhere. A licence is expressed as bounds.
     */
    public function up(): void
    {
        Schema::create('organization_package', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('package_id')->constrained()->cascadeOnDelete();
            $table->timestamp('available_until')->nullable();
            $table->string('version_min')->nullable();
            $table->string('version_max')->nullable();

            $table->primary(['organization_id', 'package_id']);
            $table->index('package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_package');
    }
};

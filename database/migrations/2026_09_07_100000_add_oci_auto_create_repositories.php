<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a `docker push` to an unregistered repository name creates that repository.
 *
 * Two columns, one relationship — the same the instance-wide ceiling has with
 * `enabled_registry_types`: the system setting is the ceiling and the organization may only
 * narrow within it (App\Services\Registry\OciSettings::autoCreateEnabledFor()).
 *
 * `false` on `system_settings` keeps the pre-upgrade behaviour after a migration: kontorfix
 * refuses a push to an unknown name unless an operator opts in deliberately. `null` on
 * `organizations` means "inherit the ceiling" and is NOT the same as `false`, which is an
 * organization that has opted out of a globally enabled feature — hence nullable with no
 * default rather than a plain boolean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->boolean('oci_auto_create_repositories')->default(false);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('oci_auto_create_repositories')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn('oci_auto_create_repositories');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('oci_auto_create_repositories');
        });
    }
};

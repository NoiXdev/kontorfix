<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `PackageType::Docker` (2026_09_02_?) added a fourth registry type, but the global
     * ceiling this settings row governs (RegistryTypeService::globalTypes()) kept its old
     * three-type default — so no organization could ever enable Docker, however it set its
     * own `enabled_registry_types`, since RegistryTypeService::effectiveFor() intersects an
     * org's choice with this ceiling.
     *
     * Only rows still at the untouched default are widened. A row an operator has already
     * edited — restricting to `['composer']`, say — is a deliberate choice about *this*
     * instance and is left alone; the new type ships available, not force-enabled over an
     * explicit restriction.
     */
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->json('enabled_registry_types')->default('["composer","npm","python","docker"]')->change();
        });

        // Postgres' `json` type has no `=` operator, so the match is cast to text —
        // matched exactly as Laravel serialises the array, since the row was created
        // exactly the same way (model attribute default / json_encode, unformatted).
        DB::table('system_settings')
            ->whereRaw('enabled_registry_types::text = ?', [json_encode(['composer', 'npm', 'python'])])
            ->update(['enabled_registry_types' => json_encode(['composer', 'npm', 'python', 'docker'])]);
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->json('enabled_registry_types')->default('["composer","npm","python"]')->change();
        });

        DB::table('system_settings')
            ->whereRaw('enabled_registry_types::text = ?', [json_encode(['composer', 'npm', 'python', 'docker'])])
            ->update(['enabled_registry_types' => json_encode(['composer', 'npm', 'python'])]);
    }
};

<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles become per-organization: a user's role in their home org stays in
     * users.role, and each additional membership carries its own role on the pivot.
     * A separate global super-admin flag grants instance-wide access to everything.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('role');
        });

        Schema::table('organization_user', function (Blueprint $table) {
            $table->string('role')->default(UserRole::Member->value)->after('user_id');
        });

        // Grandfather the existing model: an admin of the operator organization has
        // always been the "can do everything" account, so they become super-admins.
        DB::table('users')
            ->where('role', UserRole::Admin->value)
            ->whereIn('organization_id', DB::table('organizations')->where('is_operator', true)->pluck('id'))
            ->update(['is_super_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('organization_user', function (Blueprint $table) {
            $table->dropColumn('role');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            // A timestamp rather than a boolean: it costs the same single column and also
            // answers "since when", which the banner shows and which a boolean leaves
            // recoverable only by trawling the activity log.
            $table->timestamp('abandoned_at')->nullable()->after('sync_error');
            // Free text, not a foreign key: the replacement is usually a package this
            // registry does not hold (`symfony/mailer` lives on Packagist).
            $table->string('replacement_package')->nullable()->after('abandoned_at');
            $table->text('abandonment_reason')->nullable()->after('replacement_package');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn(['abandoned_at', 'replacement_package', 'abandonment_reason']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oci_scan_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('manifest_id')->constrained('oci_manifests')->cascadeOnDelete();

            // Identity of whoever produced the verdict. Part of the unique key, so switching
            // an instance from one scanner to another does not silently overwrite the old
            // scanner's answer with the new one's — they coexist and the newest wins in the
            // reader, which is what makes a scanner swap auditable.
            $table->string('scanner_name');
            $table->string('scanner_version')->nullable();

            $table->string('status')->default('pending');

            // The last SUCCESSFUL scan. Null while a manifest has never been scanned.
            $table->timestamp('scanned_at')->nullable();

            // The last FAILED attempt, independent of `status`: a stale-but-trusted verdict
            // is `status = ok` with both of these set, and the UI says so rather than
            // presenting it as fresh.
            $table->text('error')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->unsignedInteger('critical_count')->default(0);
            $table->unsignedInteger('high_count')->default(0);
            $table->unsignedInteger('medium_count')->default(0);
            $table->unsignedInteger('low_count')->default(0);
            $table->unsignedInteger('unknown_count')->default(0);

            $table->timestamps();

            $table->unique(['manifest_id', 'scanner_name']);

            // The rescan sweep's ordering: never-scanned first, then oldest. A plain index
            // on the nullable column serves both, since Postgres indexes NULLs.
            $table->index(['scanned_at']);
        });

        Schema::create('oci_scan_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('oci_scan_reports')->cascadeOnDelete();

            $table->string('vulnerability_id');
            $table->string('severity');

            // VulnerabilitySeverity::rank(), denormalised. See that enum for why, and
            // OciScanFinding::booted() for what keeps it honest — the model derives this
            // column from `severity` on every save, so no caller can set it independently.
            $table->unsignedTinyInteger('severity_rank');

            $table->string('package_name');
            $table->string('installed_version')->nullable();
            $table->string('fixed_version')->nullable();

            // NO DEFAULT, deliberately. The only correct value is decided by
            // ScanReportWriter — carried forward on a rescan, set to now() only on a
            // genuinely new finding — and a `useCurrent()` here would make the wrong value
            // (now, every time) look like the intended one.
            $table->timestamp('first_seen_at');

            $table->timestamps();

            // Keyed on the package too: one CVE legitimately affects several packages in
            // one image (an openssl advisory hits both `openssl` and `libcrypto3`), and
            // collapsing them would drop findings and make the counts disagree with the list.
            $table->unique(['report_id', 'vulnerability_id', 'package_name']);

            // The blocking rule's index: "this report's worst finding at or above rank R
            // that has been known since before T". Column order matches the predicate.
            $table->index(['report_id', 'severity_rank', 'first_seen_at']);
        });

        Schema::table('groups', function (Blueprint $table) {
            // Null = off, and off is the shipped default: switching this on can refuse a
            // customer's pull, so it is never something an upgrade decides for an operator.
            $table->string('scan_block_severity')->nullable();
            $table->unsignedSmallInteger('scan_block_grace_days')->default(7);
        });

        Schema::table('registry_tokens', function (Blueprint $table) {
            // Set only by ScanOciArtifact, never offered in the console. It exempts its
            // bearer from the blocking rule — without it a blocked artifact could never be
            // rescanned, and therefore never unblocked, because the adapter pulls the
            // artifact over the very endpoint the rule refuses.
            $table->boolean('for_scanner')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('registry_tokens', fn (Blueprint $table) => $table->dropColumn('for_scanner'));
        Schema::table('groups', fn (Blueprint $table) => $table->dropColumn(['scan_block_severity', 'scan_block_grace_days']));
        Schema::dropIfExists('oci_scan_findings');
        Schema::dropIfExists('oci_scan_reports');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oci_blobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('digest', 71);              // sha256: + 64 hex
            $table->unsignedBigInteger('size');
            $table->string('path');                    // on the artifacts disk
            $table->timestamps();
            // Per organization, NEVER global: a global index would make a blob-existence
            // check answer differently depending on what other tenants hold — an oracle
            // over foreign image contents, readable by anyone with a token.
            $table->unique(['organization_id', 'digest']);
            // The sweeper (Plan B) walks unreferenced blobs by age.
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('oci_manifests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('package_id')->constrained()->cascadeOnDelete();
            $table->string('digest', 71);
            $table->string('media_type');
            // Verbatim bytes. The digest is their hash; re-serialising would change it.
            $table->text('payload');
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->unique(['package_id', 'digest']);
            // Redundant as a uniqueness claim on its own — `id` is already the primary
            // key — but it exists so oci_tags can carry a composite foreign key on
            // (manifest_id, package_id): Postgres requires the referenced columns to be
            // covered by a unique constraint before they can be a FK target as a pair.
            // That composite FK is what stops a tag's package_id from drifting away from
            // its manifest's package_id; do not delete this as pointless.
            $table->unique(['id', 'package_id']);
        });

        Schema::create('oci_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Denormalised onto the tag rather than read through `manifest_id`: the unique
            // tag-name constraint below and "list this repository's tags" both need it
            // directly, without a join through oci_manifests. The composite foreign key on
            // (manifest_id, package_id) is what stops this copy from drifting — a plain
            // single-column FK on manifest_id alone would accept a tag whose package_id
            // names one repository and whose manifest_id names a manifest in another,
            // which is exactly the cross-repository leak this schema exists to prevent.
            $table->foreignUuid('package_id')->constrained()->cascadeOnDelete();
            $table->string('name', 128);
            // By id, not by digest: a digest is only unique WITHIN a repository, which is
            // exactly why a digest-keyed reference would need a composite (package_id,
            // digest) join to stay repository-scoped. The manifest row already carries
            // that pairing, so pointing at its id carries it too, by construction — no
            // join, no extra WHERE to remember at every call site.
            $table->uuid('manifest_id');
            $table->timestamps();
            $table->unique(['package_id', 'name']);
            $table->index(['package_id', 'updated_at']);
            // The pair, not just manifest_id: this is what makes package_id above
            // provably consistent with the manifest's own package_id, at the database,
            // rather than by convention (every write path happening to agree). Cascades
            // on delete: a manifest going away should take the tags pointing at it with
            // it. A package delete also cascades to oci_tags directly via package_id
            // above — both paths converge on exactly the same rows (a tag's package_id
            // and its manifest's package_id can no longer disagree), so Postgres deletes
            // each row once regardless of which cascade path reaches it first; the second
            // path finds nothing left to delete. Neither path needs to become
            // restrictOnDelete for the other's sake.
            $table->foreign(['manifest_id', 'package_id'])
                ->references(['id', 'package_id'])
                ->on('oci_manifests')
                ->cascadeOnDelete();
        });

        Schema::create('oci_blob_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('package_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedBigInteger('offset')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oci_blob_uploads');
        Schema::dropIfExists('oci_tags');
        Schema::dropIfExists('oci_manifests');
        Schema::dropIfExists('oci_blobs');
    }
};

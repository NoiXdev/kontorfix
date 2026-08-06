<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PyPI is file-centric: a single release version can ship many distribution files
     * (one sdist plus several wheels). That doesn't fit the version-per-row shape of
     * package_versions, so Python distributions get their own table keyed by filename.
     */
    public function up(): void
    {
        Schema::create('python_dists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('package_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->string('filename');
            $table->string('filetype'); // sdist | bdist_wheel
            $table->string('path');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('requires_python')->nullable();
            $table->boolean('yanked')->default(false);
            $table->string('yanked_reason')->nullable();
            $table->unsignedBigInteger('download_count')->default(0);
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            // One filename per package (PyPI filenames are globally unique per project).
            $table->unique(['package_id', 'filename']);
            $table->index(['package_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('python_dists');
    }
};

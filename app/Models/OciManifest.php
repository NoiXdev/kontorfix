<?php

namespace App\Models;

use Database\Factories\OciManifestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An OCI image (or index) manifest belonging to one repository (`Package`).
 *
 * `payload` is stored verbatim — the digest is the hash of those exact bytes, so
 * re-serialising parsed JSON would change the digest and break every reference to this
 * manifest, including the ones cosign writes.
 */
class OciManifest extends Model
{
    /** @use HasFactory<OciManifestFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'package_id',
        'digest',
        'media_type',
        'payload',
        'size',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * The repository this manifest was pushed to.
     *
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * The tags currently pointing at this manifest.
     *
     * Keyed on `manifest_id`, this model's primary key, rather than on `digest` — a digest
     * is only unique WITHIN a repository, so a digest-keyed reverse relation would need an
     * extra `package_id` join to stay repository-scoped. An id needs no such join: it
     * already identifies one specific manifest row, so this eager-loads correctly across
     * any number of parent manifests, including two that share a digest across
     * repositories.
     *
     * @return HasMany<OciTag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(OciTag::class, 'manifest_id');
    }
}

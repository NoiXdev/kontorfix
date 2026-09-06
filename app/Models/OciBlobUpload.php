<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An in-progress chunked blob upload session, as tracked by the OCI Distribution Spec's
 * `POST`/`PATCH`/`PUT` upload dance. `path` points at the partial file on the artifacts
 * disk and `offset` is how many bytes have been written so far.
 *
 * Intentionally has no factory: nothing but the upload endpoint should ever create one —
 * a factory would invite a test to fake a session instead of driving the real protocol.
 */
class OciBlobUpload extends Model
{
    use HasUuids;

    protected $fillable = [
        'package_id',
        'path',
        'offset',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'offset' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The repository this upload will attach its blob to once completed.
     *
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\OciBlobFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A content-addressed blob (image layer or config) stored on the artifacts disk.
 *
 * Deduplicated per organization, never globally: `(organization_id, digest)` is the unique
 * key. A global digest index would let a blob-existence check answer differently depending
 * on whether some OTHER tenant already holds that blob — an oracle over foreign image
 * contents, readable by anyone who holds a token for this instance.
 */
class OciBlob extends Model
{
    /** @use HasFactory<OciBlobFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'digest',
        'size',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * The tenant this blob belongs to. Blobs are scoped to an organization, not a package,
     * because the same layer is commonly shared across many repositories of one tenant.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}

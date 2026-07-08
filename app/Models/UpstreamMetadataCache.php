<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpstreamMetadataCache extends Model
{
    use HasUuids;

    protected $table = 'upstream_metadata_cache';

    protected $fillable = [
        'upstream_id',
        'package_name',
        'payload',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Upstream, $this>
     */
    public function upstream(): BelongsTo
    {
        return $this->belongsTo(Upstream::class);
    }
}

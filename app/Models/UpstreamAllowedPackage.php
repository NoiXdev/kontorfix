<?php

namespace App\Models;

use Database\Factories\UpstreamAllowedPackageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpstreamAllowedPackage extends Model
{
    /** @use HasFactory<UpstreamAllowedPackageFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'upstream_id',
        'name',
    ];

    /**
     * @return BelongsTo<Upstream, $this>
     */
    public function upstream(): BelongsTo
    {
        return $this->belongsTo(Upstream::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\PackageVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageVersion extends Model
{
    /** @use HasFactory<PackageVersionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'package_id',
        'version',
        'version_pretty',
        'source_reference',
        'metadata',
        'dist_path',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}

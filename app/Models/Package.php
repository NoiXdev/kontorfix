<?php

namespace App\Models;

use App\Enums\PackageType;
use App\Enums\SyncStatus;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'type',
        'name',
        'description',
        'repository_url',
        'sync_status',
        'sync_error',
        'synced_at',
        'dist_tags',
    ];

    protected function casts(): array
    {
        return [
            'type' => PackageType::class,
            'sync_status' => SyncStatus::class,
            'synced_at' => 'datetime',
            'dist_tags' => 'array',
        ];
    }

    /**
     * @return HasMany<PackageVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PackageVersion::class)->orderByDesc('released_at');
    }

    /**
     * @return BelongsToMany<Group, $this, GroupPackage>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)
            ->using(GroupPackage::class)
            ->withPivot('version_constraint', 'available_until');
    }
}

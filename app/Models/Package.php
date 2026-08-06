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
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory, HasUuids, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('package')
            ->logOnly(['name', 'type', 'repository_url', 'sync_status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected $fillable = [
        'type',
        'name',
        'description',
        'repository_url',
        'repository_token',
        'sync_status',
        'sync_error',
        'synced_at',
        'dist_tags',
    ];

    /**
     * Never serialise the git access token — it must not reach the frontend or API.
     *
     * @var list<string>
     */
    protected $hidden = [
        'repository_token',
    ];

    protected function casts(): array
    {
        return [
            'type' => PackageType::class,
            'sync_status' => SyncStatus::class,
            'synced_at' => 'datetime',
            'dist_tags' => 'array',
            // Encrypted at rest; decrypted transparently when building git auth.
            'repository_token' => 'encrypted',
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
     * Python distribution files (sdists/wheels) served over the PEP 503 simple API.
     * Only ever populated for PackageType::Python.
     *
     * @return HasMany<PythonDist, $this>
     */
    public function pythonDists(): HasMany
    {
        return $this->hasMany(PythonDist::class);
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

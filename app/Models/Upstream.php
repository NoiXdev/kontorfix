<?php

namespace App\Models;

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use Database\Factories\UpstreamFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Upstream extends Model
{
    /** @use HasFactory<UpstreamFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'group_id',
        'type',
        'url',
        'policy',
        'auth_token',
        'priority',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'type' => PackageType::class,
            'policy' => UpstreamPolicy::class,
            'auth_token' => 'encrypted',
            'priority' => 'int',
            'enabled' => 'bool',
        ];
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return HasMany<UpstreamAllowedPackage, $this>
     */
    public function allowedPackages(): HasMany
    {
        return $this->hasMany(UpstreamAllowedPackage::class);
    }

    /**
     * Ob dieses Paket über diesen Upstream ausgeliefert werden darf. Im Strict-Modus
     * nur, wenn es auf der Allowlist steht (Schutz gegen Dependency Confusion). Diese
     * eine Stelle gilt für Metadaten UND Artefakt-Download.
     */
    public function allowsPackage(string $name): bool
    {
        if ($this->policy !== UpstreamPolicy::Strict) {
            return true;
        }

        return $this->allowedPackages()->where('name', $name)->exists();
    }

    /**
     * @return HasMany<UpstreamMetadataCache, $this>
     */
    public function metadataCache(): HasMany
    {
        return $this->hasMany(UpstreamMetadataCache::class);
    }
}

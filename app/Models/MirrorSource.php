<?php

namespace App\Models;

use App\Enums\PackageType;
use App\Services\Upstream\UpstreamEndpoint;
use Database\Factories\MirrorSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A reusable, organization-scoped pointer at a foreign registry (Composer/npm/PyPI) that
 * packages can mirror from — the org-level counterpart to the per-package git repository a
 * git-sourced package already points at. The token is encrypted at rest and never serialised.
 *
 * @property string $organization_id
 * @property string $name
 * @property PackageType $type
 * @property string $url
 * @property string|null $auth_token
 * @property Carbon|null $last_used_at
 */
class MirrorSource extends Model implements UpstreamEndpoint
{
    /** @use HasFactory<MirrorSourceFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'name',
        'type',
        'url',
        'auth_token',
        'last_used_at',
    ];

    /**
     * Never serialise the auth token — it must not reach the frontend or API.
     *
     * @var list<string>
     */
    protected $hidden = [
        'auth_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PackageType::class,
            'auth_token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * The organization that owns this mirror source. A mirror source is usable only by the
     * packages of the organization it belongs to — there is no cross-organization sharing
     * the way GitCredential has.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Packages populated from this mirror source.
     *
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwnedBy(Builder $query, string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function endpointUrl(): string
    {
        return $this->url;
    }

    public function endpointToken(): ?string
    {
        return $this->auth_token;
    }
}

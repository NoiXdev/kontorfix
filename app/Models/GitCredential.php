<?php

namespace App\Models;

use App\Enums\GitProvider;
use Database\Factories\GitCredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A reusable git access token, scoped to an organization, assignable to packages for
 * syncing private repositories. The token is encrypted at rest and never serialised.
 *
 * @property string $name
 * @property GitProvider $provider
 * @property string|null $username
 * @property string $token
 * @property Carbon|null $last_used_at
 */
class GitCredential extends Model
{
    /** @use HasFactory<GitCredentialFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'name',
        'provider',
        'username',
        'token',
        'last_used_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => GitProvider::class,
            'token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }
}

<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\OidcProviderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $client_secret
 * @property UserRole $default_role
 */
class OidcProvider extends Model
{
    /** @use HasFactory<OidcProviderFactory> */
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'client_id',
        'client_secret',
        'issuer',
        'authorization_endpoint',
        'token_endpoint',
        'userinfo_endpoint',
        'jwks_uri',
        'scopes',
        'enabled',
        'allow_registration',
        'trusts_email_claim',
        'default_organization_id',
        'default_role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'client_secret',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'enabled' => 'bool',
            'allow_registration' => 'bool',
            'trusts_email_claim' => 'bool',
            'default_role' => UserRole::class,
        ];
    }

    /**
     * @return HasMany<OidcIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(OidcIdentity::class);
    }

    public function hasSecret(): bool
    {
        return filled($this->client_secret);
    }
}

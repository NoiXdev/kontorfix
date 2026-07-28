<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccountType;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\PasskeyAuthenticatable;

/**
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_timestamp
 * @property-read Collection<int, Passkey> $passkeys
 */
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, PasskeyAuthenticatable;

    /**
     * Default values for new (not yet loaded from the DB) model instances.
     * Keeps the in-memory state in sync with the DB default of `account_type`,
     * since UUID inserts don't return defaulted columns via RETURNING.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'account_type' => 'human',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'organization_id',
        'role',
        'account_type',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'account_type' => AccountType::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_timestamp' => 'integer',
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
     * @return HasMany<OidcIdentity, $this>
     */
    public function oidcIdentities(): HasMany
    {
        return $this->hasMany(OidcIdentity::class);
    }

    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function isRobot(): bool
    {
        return $this->account_type === AccountType::Robot;
    }

    public function hasEnabledTwoFactor(): bool
    {
        return ! is_null($this->two_factor_secret);
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->hasEnabledTwoFactor() && ! is_null($this->two_factor_confirmed_at);
    }

    /** @return list<string> */
    public function recoveryCodes(): array
    {
        return $this->two_factor_recovery_codes ?? [];
    }

    /** Consumes (removes) exactly one recovery code and saves. */
    public function replaceRecoveryCode(string $code): void
    {
        $this->forceFill([
            'two_factor_recovery_codes' => array_values(array_filter(
                $this->recoveryCodes(),
                fn (string $c) => ! hash_equals($c, $code),
            )),
        ])->save();
    }
}

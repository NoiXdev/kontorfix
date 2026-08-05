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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

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
    use HasFactory, HasUuids, LogsActivity, Notifiable, PasskeyAuthenticatable;

    public function getActivitylogOptions(): LogOptions
    {
        // Deliberately never logs password / 2FA columns — only safe profile fields.
        return LogOptions::defaults()
            ->useLogName('user')
            ->logOnly(['name', 'email', 'role', 'is_super_admin', 'organization_id', 'account_type'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

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
        'is_super_admin',
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
            'is_super_admin' => 'bool',
            'account_type' => AccountType::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_timestamp' => 'integer',
        ];
    }

    /**
     * The user's home organization — anchors role and operator status.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Additional organizations the user was granted access to, beyond the home org.
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Global super-admin: may do everything across every organization. The admin of the
     * operator organization is grandfathered in (that account has always been the
     * "can do everything" login), so existing installs keep working.
     */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin
            || ($this->role === UserRole::Admin && (bool) $this->organization?->is_operator);
    }

    /**
     * The user's role within a specific organization: their home-org role for the home
     * org, the membership role for an additional org, or null when they are not a member.
     * A super-admin is treated as admin everywhere.
     */
    public function roleIn(?string $organizationId): ?UserRole
    {
        if ($organizationId === null) {
            return null;
        }

        if ($this->isSuperAdmin()) {
            return UserRole::Admin;
        }

        if ($organizationId === $this->organization_id) {
            return $this->role;
        }

        $membership = $this->organizations->firstWhere('id', $organizationId)
            ?? $this->organizations()->whereKey($organizationId)->first();

        $pivotRole = $membership?->getRelationValue('pivot')?->getAttribute('role');

        return $pivotRole !== null ? UserRole::from($pivotRole) : null;
    }

    /** Whether the user is admin/maintainer of the given organization (or super-admin). */
    public function administers(?string $organizationId): bool
    {
        return in_array($this->roleIn($organizationId), [UserRole::Admin, UserRole::Maintainer], true);
    }

    /**
     * Organizations the user may administer (admin or maintainer). A super-admin
     * administers every organization.
     *
     * @return list<string>
     */
    public function administeredOrganizationIds(): array
    {
        if ($this->isSuperAdmin()) {
            return Organization::query()->pluck('id')->all();
        }

        $ids = [];

        if (in_array($this->role, [UserRole::Admin, UserRole::Maintainer], true) && $this->organization_id !== null) {
            $ids[] = $this->organization_id;
        }

        foreach ($this->organizations as $org) {
            $pivotRole = $org->getRelationValue('pivot')?->getAttribute('role');
            if (in_array($pivotRole, [UserRole::Admin->value, UserRole::Maintainer->value], true)) {
                $ids[] = $org->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Whether the user may reach the admin console at all (super-admin or org admin/maintainer). */
    public function canAdministerConsole(): bool
    {
        return $this->isSuperAdmin() || $this->administeredOrganizationIds() !== [];
    }

    /**
     * All organization ids the user can see registries of: the home org plus every
     * additional membership. This is the set portal visibility and token scoping
     * are built on.
     *
     * @return list<string>
     */
    public function accessibleOrganizationIds(): array
    {
        return collect([$this->organization_id])
            ->merge($this->organizations()->pluck('organizations.id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function belongsToOrganization(string $organizationId): bool
    {
        return in_array($organizationId, $this->accessibleOrganizationIds(), true);
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

<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('organization')
            ->logOnly(['name', 'slug', 'is_operator'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected $fillable = ['name', 'slug', 'is_operator', 'enabled_registry_types'];

    protected function casts(): array
    {
        return [
            'is_operator' => 'bool',
            // Null = inherit the instance-wide set; otherwise a restriction within it.
            'enabled_registry_types' => 'array',
        ];
    }

    /**
     * Users whose home organization this is.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Users granted additional access to this organization (excludes home members).
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<Group, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /**
     * @return HasMany<RegistryToken, $this>
     */
    public function registryTokens(): HasMany
    {
        return $this->hasMany(RegistryToken::class);
    }
}

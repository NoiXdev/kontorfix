<?php

namespace App\Models;

use App\Enums\TokenAbility;
use Database\Factories\RegistryTokenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistryToken extends Model
{
    /** @use HasFactory<RegistryTokenFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'group_id',
        'name',
        'token_hash',
        'ability',
        'last_used_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'ability' => TokenAbility::class,
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}

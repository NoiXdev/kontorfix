<?php

namespace App\Models;

use App\Enums\TokenAbility;
use Database\Factories\RegistryTokenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property string|null $user_id
 * @property string|null $plain_text Nur direkt nach issue() gesetzt, nie persistiert.
 */
class RegistryToken extends Model
{
    /** @use HasFactory<RegistryTokenFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'user_id',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{0: self, 1: string}
     */
    public static function issue(Organization $org, string $name, ?Group $group, TokenAbility $ability = TokenAbility::Read, ?\DateTimeInterface $expiresAt = null, ?User $owner = null): array
    {
        $plain = 'kfx_'.Str::random(40);
        $token = static::create([
            'organization_id' => $org->id,
            'user_id' => $owner?->id,
            'group_id' => $group?->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'ability' => $ability,
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plain];
    }

    public static function findByPlainText(string $plain): ?self
    {
        return static::query()
            ->where('token_hash', hash('sha256', $plain))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }
}

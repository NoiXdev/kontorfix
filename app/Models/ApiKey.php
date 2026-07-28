<?php

namespace App\Models;

use App\Enums\ApiKeyPermission;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property string|null $plain_text Only set directly after issue(), never persisted.
 */
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'name',
        'key_hash',
        'permission',
        'last_used_at',
        'expires_at',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected function casts(): array
    {
        return [
            'permission' => ApiKeyPermission::class,
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
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
    public static function issue(User $owner, string $name, ApiKeyPermission $permission = ApiKeyPermission::Read, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = 'kfxapi_'.Str::random(40);
        $key = static::create([
            'user_id' => $owner->id,
            'name' => $name,
            'key_hash' => hash('sha256', $plain),
            'permission' => $permission,
            'expires_at' => $expiresAt,
        ]);

        return [$key, $plain];
    }

    public static function findByPlainText(string $plain): ?self
    {
        return static::query()
            ->where('key_hash', hash('sha256', $plain))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }
}

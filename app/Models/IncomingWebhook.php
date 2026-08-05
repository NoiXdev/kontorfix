<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $name
 * @property string $provider
 * @property string $secret
 * @property bool $enabled
 * @property Carbon|null $last_received_at
 */
class IncomingWebhook extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'provider',
        'secret',
        'enabled',
        'last_received_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'enabled' => 'bool',
            'last_received_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<IncomingWebhookEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(IncomingWebhookEvent::class);
    }
}

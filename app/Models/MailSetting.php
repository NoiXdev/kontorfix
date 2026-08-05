<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $mailer
 * @property string|null $from_address
 * @property string|null $from_name
 * @property string|null $smtp_host
 * @property int|null $smtp_port
 * @property string|null $smtp_username
 * @property string|null $smtp_password
 * @property string|null $smtp_encryption
 * @property string|null $postal_domain
 * @property string|null $postal_key
 */
class MailSetting extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'mailer',
        'from_address',
        'from_name',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'postal_domain',
        'postal_key',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'smtp_password',
        'postal_key',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'smtp_password' => 'encrypted',
            'postal_key' => 'encrypted',
            'smtp_port' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOr(fn () => static::query()->create(['mailer' => 'log']));
    }
}

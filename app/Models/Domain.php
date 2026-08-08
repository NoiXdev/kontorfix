<?php

namespace App\Models;

use App\Services\Http\TrustedHosts;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory, HasUuids, LogsActivity;

    /**
     * A hostname attachment is an instance-wide claim on a globally unique name, so it
     * needs a record of who made it and when — without one, a mistaken or malicious
     * attachment is invisible after the fact (the victim tenant cannot see the row at all).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('registry')
            ->logOnly(['hostname', 'group_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected $fillable = [
        'group_id',
        'hostname',
    ];

    /**
     * An attached hostname is part of the `Host` allowlist (see TrustedHosts), which is
     * cached in front of every request. Without this, attaching a domain would leave it
     * refused with a 400 until the cache expired.
     */
    protected static function booted(): void
    {
        $forget = fn () => Cache::forget(TrustedHosts::CACHE_KEY);

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}

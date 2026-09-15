<?php

namespace App\Models;

use App\Enums\VulnerabilitySeverity;
use Database\Factories\OciScanFindingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One vulnerability, in one package, in one scanned manifest.
 *
 * `first_seen_at` is the load-bearing column of this whole feature. It records when THIS
 * pairing was first observed, and the grace period is measured from it — not from the scan
 * that reported it (a nightly rescan would reset the clock and nothing would ever block)
 * and not from the advisory's publication date (one database update would block every
 * artifact on the instance at once). ScanReportWriter is its only writer.
 *
 * @property VulnerabilitySeverity $severity
 * @property Carbon $first_seen_at
 */
class OciScanFinding extends Model
{
    /** @use HasFactory<OciScanFindingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'report_id',
        'vulnerability_id',
        'severity',
        'severity_rank',
        'package_name',
        'installed_version',
        'fixed_version',
        'first_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'severity' => VulnerabilitySeverity::class,
            'severity_rank' => 'integer',
            'first_seen_at' => 'datetime',
        ];
    }

    /** The day this finding starts blocking under a given grace period. */
    public function blocksAt(int $graceDays): Carbon
    {
        return $this->first_seen_at->copy()->addDays($graceDays);
    }

    /**
     * @return BelongsTo<OciScanReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(OciScanReport::class, 'report_id');
    }
}

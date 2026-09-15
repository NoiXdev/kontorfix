<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Database\Factories\OciScanReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One scanner's verdict on one manifest.
 *
 * Keyed on the MANIFEST and not on the tag: a tag is a mutable name, and re-pointing
 * `latest` at a different image must not inherit the old image's clean bill of health. Two
 * tags on one manifest share one report by construction, which is also why ScanOciArtifact
 * is unique per manifest rather than per tag.
 *
 * @property ScanStatus $status
 * @property Carbon|null $scanned_at The last SUCCESSFUL scan.
 * @property Carbon|null $failed_at The last failed attempt — set independently of $status.
 */
class OciScanReport extends Model
{
    /** @use HasFactory<OciScanReportFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'manifest_id',
        'scanner_name',
        'scanner_version',
        'status',
        'scanned_at',
        'error',
        'failed_at',
        'critical_count',
        'high_count',
        'medium_count',
        'low_count',
        'unknown_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => ScanStatus::class,
            'scanned_at' => 'datetime',
            'failed_at' => 'datetime',
            'critical_count' => 'integer',
            'high_count' => 'integer',
            'medium_count' => 'integer',
            'low_count' => 'integer',
            'unknown_count' => 'integer',
        ];
    }

    /**
     * Whether this verdict is trustworthy but out of date — a scan succeeded once and every
     * attempt since has failed. Rendered as its own state everywhere, because presenting it
     * as fresh is how an operator stops noticing a scanner that died three weeks ago.
     */
    public function isStale(): bool
    {
        return $this->status === ScanStatus::Ok && $this->failed_at !== null;
    }

    /**
     * @return BelongsTo<OciManifest, $this>
     */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(OciManifest::class, 'manifest_id');
    }

    /**
     * @return HasMany<OciScanFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(OciScanFinding::class, 'report_id');
    }
}

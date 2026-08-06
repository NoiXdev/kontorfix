<?php

namespace App\Models;

use Database\Factories\PythonDistFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single Python distribution file (an sdist or a wheel) served over the PEP 503
 * "simple" API. Multiple files can belong to the same release version.
 *
 * @property string $version
 * @property string $filename
 * @property string $filetype
 * @property string $path
 * @property string|null $source_reference
 * @property string $sha256
 * @property int $size
 * @property string|null $requires_python
 * @property bool $yanked
 * @property string|null $yanked_reason
 * @property int $download_count
 * @property Carbon|null $uploaded_at
 */
class PythonDist extends Model
{
    /** @use HasFactory<PythonDistFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'package_id',
        'version',
        'filename',
        'filetype',
        'path',
        'source_reference',
        'sha256',
        'size',
        'requires_python',
        'yanked',
        'yanked_reason',
        'download_count',
        'uploaded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'yanked' => 'bool',
            'download_count' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}

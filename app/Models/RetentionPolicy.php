<?php

namespace App\Models;

use Database\Factories\RetentionPolicyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, reusable retention rule set. Operator-defined and instance-wide; a package
 * selects one, and `system_settings` selects the instance default.
 *
 * The rules stay a plain array here rather than being cast to value objects: this model is
 * also what the editor's form binds to, and a policy is legitimately half-written while
 * being edited. App\Support\Retention\RetentionRule is where a rule becomes valid or
 * refuses to exist, which is the boundary the evaluator sits behind.
 *
 * @property string $name
 * @property array<int, array<string, mixed>> $rules
 * @property bool $is_global
 */
class RetentionPolicy extends Model
{
    /** @use HasFactory<RetentionPolicyFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'rules', 'is_global'];

    /**
     * Stated here as well as in the migration's column default, for the reason
     * SystemSetting gives: a freshly constructed model must carry a truthful flag rather
     * than null until it has been read back from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_global' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rules' => 'array',
            // Visible to and selectable by every organization, editable only by the
            // operator — a publication flag, not shared authorship.
            'is_global' => 'bool',
        ];
    }

    /**
     * The packages that select this policy directly.
     *
     * Deliberately NOT the set of packages this policy governs: as the instance default it
     * also governs every Docker package that names no policy at all, and those rows do not
     * appear here. That wider set is a query over the resolution chain, and
     * App\Services\Oci\Retention\RetentionRunner::packagesFor() owns it — using this
     * relation for it would silently under-report the default policy's reach to exactly
     * zero on a fresh instance.
     *
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }
}

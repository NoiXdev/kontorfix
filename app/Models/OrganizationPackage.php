<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The `organization_package` row: one customer's licence for one shared package.
 *
 * Mirrors {@see GroupPackage} — it exists for the same two reasons that one does: the
 * `available_until` cast, so callers compare a date against a date rather than against a
 * string, and a named pivot type so the bounds columns are visible to static analysis
 * instead of being untyped magic properties on the base Pivot.
 */
class OrganizationPackage extends Pivot
{
    /** The pair IS the identity; there is no surrogate key. */
    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'available_until' => 'datetime',
        ];
    }
}

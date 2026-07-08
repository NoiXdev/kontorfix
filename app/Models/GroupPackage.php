<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class GroupPackage extends Pivot
{
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

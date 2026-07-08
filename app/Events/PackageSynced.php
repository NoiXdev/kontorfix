<?php

namespace App\Events;

use App\Models\Package;
use Illuminate\Foundation\Events\Dispatchable;

class PackageSynced
{
    use Dispatchable;

    public function __construct(public Package $package) {}
}

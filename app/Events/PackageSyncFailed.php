<?php

namespace App\Events;

use App\Models\Package;
use Illuminate\Foundation\Events\Dispatchable;

class PackageSyncFailed
{
    use Dispatchable;

    public function __construct(public Package $package, public string $error) {}
}

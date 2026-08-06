<?php

namespace App\Services\Python;

class PythonName
{
    /**
     * Normalise a project name per PEP 503: lowercase and collapse any run of
     * runs of `-`, `_` or `.` into a single `-`. pip requests the simple index
     * under this canonical form, so lookups must normalise both sides.
     */
    public static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[-_.]+/', '-', trim($name)));
    }
}

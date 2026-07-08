<?php

return [

    /*
    |--------------------------------------------------------------------------
    | npm-Publish-Limits
    |--------------------------------------------------------------------------
    |
    | Obergrenze für einen einzelnen hochgeladenen npm-Tarball. Schützt vor
    | Memory-/Disk-Erschöpfung durch Publish-Token-Inhaber. In Bytes.
    |
    */

    'npm_max_tarball_bytes' => (int) env('KONTORFIX_NPM_MAX_TARBALL_BYTES', 100 * 1024 * 1024),

];

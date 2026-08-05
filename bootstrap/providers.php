<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\MailServiceProvider;
use App\Providers\StorageServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    MailServiceProvider::class,
    StorageServiceProvider::class,
];

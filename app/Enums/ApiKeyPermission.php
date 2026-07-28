<?php

namespace App\Enums;

enum ApiKeyPermission: string
{
    case Read = 'read';
    case Write = 'write';
}

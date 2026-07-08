<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Maintainer = 'maintainer';
    case Member = 'member';
}

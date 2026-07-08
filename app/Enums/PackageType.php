<?php

namespace App\Enums;

enum PackageType: string
{
    case Composer = 'composer';
    case Npm = 'npm';
}

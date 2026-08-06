<?php

namespace App\Enums;

enum PackageType: string
{
    case Composer = 'composer';
    case Npm = 'npm';
    case Python = 'python';

    /** Whether packages of this type are populated by pushing artifacts (npm publish, twine upload) rather than git sync. */
    public function isPublishBased(): bool
    {
        return match ($this) {
            self::Npm, self::Python => true,
            self::Composer => false,
        };
    }
}

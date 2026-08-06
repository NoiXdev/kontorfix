<?php

namespace App\Enums;

/**
 * How a package is populated. Publish-based packages receive their versions by pushing
 * artifacts (npm publish, twine upload); git-mirror packages import versions from the
 * tags of a git repository (like Composer always does). Composer is always git-sourced;
 * npm and Python may be either.
 */
enum PackageSourceMode: string
{
    case Publish = 'publish';
    case Git = 'git';

    public function label(): string
    {
        return match ($this) {
            self::Publish => 'Publish (Push)',
            self::Git => 'Git-Mirror',
        };
    }

    /**
     * @return list<array{value:string, label:string}>
     */
    public static function metadata(): array
    {
        return array_map(fn (self $m): array => [
            'value' => $m->value,
            'label' => $m->label(),
        ], self::cases());
    }
}

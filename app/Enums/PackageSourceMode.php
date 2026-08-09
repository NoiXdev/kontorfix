<?php

namespace App\Enums;

/**
 * How a package is populated. Publish-based packages receive their versions by pushing
 * artifacts (npm publish, twine upload); git-mirror packages import versions from the
 * tags of a git repository.
 *
 * Which modes a type may use is decided by allowedFor(), the single source of truth.
 * Composer is always git-sourced: a Composer package *is* its source tree. Python may be
 * either, because pip builds from a source distribution at install time. npm is publish
 * only — what `npm publish` uploads is a built artifact, not the repository tree, so a
 * mirror produces a package with the right name and the wrong contents.
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
     * The modes a package of this type may use. Order matters: the first entry is the
     * default, and the create dialog renders a selector only when there is more than one.
     *
     * @return list<self>
     */
    public static function allowedFor(PackageType $type): array
    {
        return match ($type) {
            PackageType::Composer => [self::Git],
            PackageType::Npm => [self::Publish],
            PackageType::Python => [self::Publish, self::Git],
        };
    }

    public static function defaultFor(PackageType $type): self
    {
        return self::allowedFor($type)[0];
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

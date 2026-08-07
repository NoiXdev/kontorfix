<?php

namespace App\Enums;

/**
 * The registry types the instance can host. This enum is the single source of truth for
 * per-type behaviour (labels, whether it is publish-based, its manifest file, its name
 * format, and the install hint). The metadata is shared to the frontend via Inertia
 * (`registryTypeMeta`) so dropdowns, filters and install snippets are all driven from
 * here — adding a type means editing this file, not chasing hardcoded lists.
 */
enum PackageType: string
{
    case Composer = 'composer';
    case Npm = 'npm';
    case Python = 'python';

    /** Human-facing label. */
    public function label(): string
    {
        return match ($this) {
            self::Composer => 'Composer',
            self::Npm => 'npm',
            self::Python => 'Python',
        };
    }

    /**
     * Whether packages of this type are populated by pushing artifacts (npm publish,
     * twine upload) rather than by syncing a git repository.
     */
    public function isPublishBased(): bool
    {
        return match ($this) {
            self::Npm, self::Python => true,
            self::Composer => false,
        };
    }

    /** The manifest file read from a git repo to discover name/description. */
    public function manifestFile(): string
    {
        return match ($this) {
            self::Composer => 'composer.json',
            self::Npm => 'package.json',
            self::Python => 'pyproject.toml',
        };
    }

    /** Install command shown to consumers. */
    public function installHint(string $name): string
    {
        return match ($this) {
            self::Composer => "composer require {$name}",
            self::Npm => "npm install {$name}",
            self::Python => "pip install {$name}",
        };
    }

    /** Validation regex for a package name of this type. */
    public function nameRegex(): string
    {
        return match ($this) {
            self::Npm => '/^(@[a-z0-9._-]+\/)?[a-z0-9._-]+$/',
            self::Python => '/^([A-Za-z0-9]|[A-Za-z0-9][A-Za-z0-9._-]*[A-Za-z0-9])$/',
            self::Composer => '/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/',
        };
    }

    /**
     * Static metadata for every type — shared to the frontend so it can render type
     * pickers/labels/install hints without hardcoding.
     *
     * @return list<array{value:string, label:string, publish_based:bool}>
     */
    public static function metadata(): array
    {
        return array_map(fn (self $t): array => [
            'value' => $t->value,
            'label' => $t->label(),
            'publish_based' => $t->isPublishBased(),
        ], self::cases());
    }
}

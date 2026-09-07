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
    case Docker = 'docker';

    /** Human-facing label. */
    public function label(): string
    {
        return match ($this) {
            self::Composer => 'Composer',
            self::Npm => 'npm',
            self::Python => 'Python',
            self::Docker => 'Docker',
        };
    }

    /**
     * Whether packages of this type are populated by pushing artifacts (npm publish,
     * twine upload, docker push) rather than by syncing a git repository.
     */
    public function isPublishBased(): bool
    {
        return match ($this) {
            self::Npm, self::Python, self::Docker => true,
            self::Composer => false,
        };
    }

    /** The manifest file read from a git repo to discover name/description. */
    public function manifestFile(): ?string
    {
        return match ($this) {
            self::Composer => 'composer.json',
            self::Npm => 'package.json',
            self::Python => 'pyproject.toml',
            // A Docker repository is never git-sourced — there is no manifest to read from a
            // clone. Null rather than a throw: an enum accessor that can explode turns every
            // call site into a try/catch, and callers that care should be branching on
            // isPublishBased() anyway.
            self::Docker => null,
        };
    }

    /**
     * Install command shown to consumers.
     *
     * `$dockerRegistry` is Docker-only and optional: every other case ignores it outright,
     * so every existing caller stays correct unchanged. It is everything an image reference
     * writes BEFORE the repository name — host, plus the `{organisation}/{registry}`
     * namespace when the registry is addressed on the instance host — and there is exactly
     * one place that computes it: RegistryUrl::dockerImagePrefix().
     *
     * The parameter used to be a bare host, supplied only when the registry carried a
     * domain, on the reasoning that a registry without one had no address a Docker client
     * could reach. ResolveOciContext made that false: every registry has one. So a caller
     * holding a Group always has a real value to pass, and the `<registry-host>` fallback
     * below now means only "this caller has no registry in hand" — never "this registry has
     * no address".
     */
    public function installHint(string $name, ?string $dockerRegistry = null): string
    {
        return match ($this) {
            self::Composer => "composer require {$name}",
            self::Npm => "npm install {$name}",
            self::Python => "pip install {$name}",
            self::Docker => 'docker pull '.($dockerRegistry ?? '<registry-host>')."/{$name}",
        };
    }

    /** Validation regex for a package name of this type. */
    public function nameRegex(): string
    {
        return match ($this) {
            self::Npm => '/^(@[a-z0-9._-]+\/)?[a-z0-9._-]+$/',
            self::Python => '/^([A-Za-z0-9]|[A-Za-z0-9][A-Za-z0-9._-]*[A-Za-z0-9])$/',
            self::Composer => '/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/',
            // The OCI Distribution Spec's repository name grammar: one or more
            // lowercase-alphanumeric path components, each optionally punctuated by
            // ./_/__/- separators, joined by "/" (e.g. "team/image" or "library/nginx").
            self::Docker => '/^[a-z0-9]+((\.|_{1,2}|-+)[a-z0-9]+)*(\/[a-z0-9]+((\.|_{1,2}|-+)[a-z0-9]+)*)*$/',
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

<?php

namespace App\Enums;

/**
 * The registry types the instance can host. This enum is the single source of truth for
 * per-type behaviour (labels, whether it is publish-based, its manifest file, and its name
 * format). The metadata is shared to the frontend via Inertia (`registryTypeMeta`) so
 * dropdowns and filters are all driven from here — adding a type means editing this file,
 * not chasing hardcoded lists.
 *
 * INSTALL COMMANDS ARE NOT HERE, and the block where installHint() used to sit says why: a
 * command needs the address of the registry serving it, which no enum case can know.
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

    /*
     * THERE IS DELIBERATELY NO installHint() HERE ANY MORE.
     *
     * This enum used to build the install command for each type, and the portal rendered
     * what it returned. It could only ever build a REGISTRY-LESS one — an enum case knows
     * its ecosystem and nothing about the registry serving it — and for Python that is not a
     * missing convenience but a live defect: `pip install kernmodul` without `--index-url`
     * resolves against PyPI, so a package of that name on the public index is installed into
     * the customer's build instead, silently. Composer and npm at least fail visibly.
     *
     * The command belongs where the registry's address already lives, and there is exactly
     * one such place: App\Services\Registry\SetupSnippetBuilder::installCommand(), built on
     * RegistryUrl. The method was removed rather than deprecated, because an unused generator
     * of a wrong command is an invitation to the next caller.
     */

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
     * pickers, labels and filter options without hardcoding a case list.
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

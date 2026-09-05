<?php

namespace App\Services\Package;

use App\Enums\PackageType;
use App\Services\Python\PythonName;

/**
 * The identity two packages collide on: the type, plus the name in the form the resolver of
 * that type actually matches.
 *
 * Composer and npm resolve the stored string verbatim. PyPI resolves a PEP 503-normalised
 * name, so `Shared_Lib`, `shared.lib` and `shared-lib` are ONE Python project, and any rule
 * that compares the stored strings answers "different name" for three spellings pip cannot
 * tell apart.
 *
 * Stated once, here, because every site that answers "is this the same package name" has to
 * answer it the way the registry does:
 *
 *   - {@see SharedAssignment} refuses an assignment that would put a shared and an own
 *     package of one name into one registry. Compared verbatim, a shared `shared-lib` was
 *     accepted into a registry already serving an own `Shared_Lib` — and then silently
 *     shadowed by it, because PypiController::pythonPackagesOfGroup() orders own-first, so
 *     the operator's deliberate assignment did nothing at all. The mirror direction let an
 *     own `Shared_Lib` be created under a name a shared `shared-lib` was serving, which
 *     stopped the shared project resolving from that moment.
 *   - Admin\GroupController's `owned_by_registry_org` mirrors clause 1 of
 *     ResolvesRegistryPackage::packageExistsLocally() and of
 *     Registry\PypiController::pythonExistsLocally(), which suppress the upstream for a name
 *     this registry's organization owns.
 *
 * SQL cannot state the normalisation, so callers that need it filter in PHP rather than
 * restating the rule in a `where`.
 */
class PackageNameKey
{
    public static function for(PackageType $type, string $name): string
    {
        return $type->value.' '.($type === PackageType::Python ? PythonName::normalize($name) : $name);
    }
}

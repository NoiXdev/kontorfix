<?php

namespace App\Services\Registry;

use App\Enums\PackageType;
use App\Models\Organization;
use App\Models\SystemSetting;

/**
 * Resolves which registry types (composer/npm/python) are active. The system setting is
 * the instance-wide ceiling; an organization may only restrict further within it (never
 * enable a type the instance has switched off).
 */
class RegistryTypeService
{
    /**
     * The instance-wide ceiling.
     *
     * @return list<string>
     */
    public function globalTypes(): array
    {
        return SystemSetting::current()->enabled_registry_types;
    }

    /**
     * Effective set for an organization: the global ceiling intersected with the org's own
     * restriction (null = inherit the whole ceiling).
     *
     * @return list<string>
     */
    public function effectiveFor(?Organization $organization): array
    {
        $global = $this->globalTypes();

        $orgTypes = $organization?->enabled_registry_types;
        if (! is_array($orgTypes)) {
            return $global;
        }

        return array_values(array_intersect($global, $orgTypes));
    }

    public function isEnabledFor(?Organization $organization, PackageType $type): bool
    {
        return in_array($type->value, $this->effectiveFor($organization), true);
    }

    public function isGloballyEnabled(PackageType $type): bool
    {
        return in_array($type->value, $this->globalTypes(), true);
    }

    /** @return list<string> */
    public function allTypes(): array
    {
        return array_map(fn (PackageType $t): string => $t->value, PackageType::cases());
    }
}

<?php

namespace App\Services\Package;

use App\Enums\PackageType;

class PackageDependencies
{
    /**
     * @param  array<string,mixed>  $metadata
     * @return array{runtime: array<string,string>, dev: array<string,string>}
     */
    public function for(PackageType $type, array $metadata): array
    {
        [$runtimeKey, $devKey] = $type === PackageType::Npm
            ? ['dependencies', 'devDependencies']
            : ['require', 'require-dev'];

        return [
            'runtime' => $this->stringMap($metadata[$runtimeKey] ?? null),
            'dev' => $this->stringMap($metadata[$devKey] ?? null),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $name => $constraint) {
            if (is_string($name) && is_string($constraint)) {
                $out[$name] = $constraint;
            }
        }

        return $out;
    }
}

<?php

namespace App\Http\Middleware;

use App\Enums\PackageType;
use App\Models\Group;
use App\Services\Registry\RegistryTypeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks registry protocol traffic for a type that is disabled for the resolved group's
 * organization. Runs after ResolveRegistryContext (which sets `registryGroup`). A disabled
 * type behaves as if the registry does not exist → 404, so neither pulls nor publishes work.
 */
class EnsureRegistryTypeEnabled
{
    public function __construct(private readonly RegistryTypeService $types) {}

    public function handle(Request $request, Closure $next, string $type): Response
    {
        $group = $request->attributes->get('registryGroup');
        $packageType = PackageType::tryFrom($type);

        if ($group instanceof Group && $packageType !== null) {
            abort_unless($this->types->isEnabledFor($group->organization, $packageType), 404);
        }

        return $next($request);
    }
}

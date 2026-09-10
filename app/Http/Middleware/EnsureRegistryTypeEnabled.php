<?php

namespace App\Http\Middleware;

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Services\Registry\RegistryTypeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks registry protocol traffic for a type that is disabled for the resolved group's (or,
 * under `/o/{orgSlug}`, the resolved organization's) enabled set. Runs after whichever
 * middleware set `registryGroup`/`registryOrganization` — ResolveRegistryContext on the
 * slug, org and custom-domain access paths, ResolveOciContext under `/v2` (see
 * routes/registry.php, where the ordering within the array is itself load-bearing). A
 * disabled type behaves as if the registry does not exist → 404, so neither pulls nor
 * publishes work.
 */
class EnsureRegistryTypeEnabled
{
    public function __construct(private readonly RegistryTypeService $types) {}

    public function handle(Request $request, Closure $next, string $type): Response
    {
        $group = $request->attributes->get('registryGroup');
        $organization = $request->attributes->get('registryOrganization');
        $packageType = PackageType::tryFrom($type);

        // No group and no organization means nothing whose enabled types could be asked
        // about, so this passes through — and that silent no-op is LOAD-BEARING, not merely
        // defensive: the bare `GET /v2/` on the instance's own host names no registry yet
        // (ResolveOciContext resolves none), and it is this middleware standing down that
        // lets VersionController answer the protocol handshake there at all. Turning the
        // missing group into a 404 would make `docker login <instance>` fail against every
        // path-addressed registry.
        if ($group instanceof Group && $packageType !== null) {
            abort_unless($this->types->isEnabledFor($group->organization, $packageType), 404);
        } elseif ($organization instanceof Organization && $packageType !== null) {
            // The `/o/{orgSlug}` mount: no single group to ask, but the organization itself
            // carries the same restriction — same rule, same refusal shape, as the group
            // branch above (a disabled type behaves as if the endpoint does not exist).
            abort_unless($this->types->isEnabledFor($organization, $packageType), 404);
        }

        return $next($request);
    }
}

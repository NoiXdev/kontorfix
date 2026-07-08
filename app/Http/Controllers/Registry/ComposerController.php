<?php

namespace App\Http\Controllers\Registry;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Services\Composer\ComposerMetadataBuilder;
use App\Services\RegistryAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComposerController extends Controller
{
    public function __construct(
        private readonly RegistryAccessService $access,
        private readonly ComposerMetadataBuilder $metadata,
    ) {}

    public function root(Request $request, Group $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);

        return response()->json([
            'metadata-url' => "/r/{$group->slug}/p2/%package%.json",
            'available-packages' => $this->access->packagesFor($group)->pluck('name')->sort()->values(),
        ]);
    }

    public function metadata(Request $request, Group $group, string $vendor, string $name): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        $package = $this->findAccessible($request, $group, "{$vendor}/{$name}");

        return response()->json($this->metadata->build($package, $group, $request->getSchemeAndHttpHost()));
    }

    public function dist(Request $request, Group $group, string $vendor, string $name, string $version): never
    {
        abort(501, 'Dist download implemented in Task 10.');
    }

    protected function authorizeGroup(Request $request, Group $group): void
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');
        if (! $this->access->canAccessGroup($token, $group)) {
            abort($token ? 404 : 401, 'Authentication required for this registry.');
        }
    }

    protected function findAccessible(Request $request, Group $group, string $fullName): Package
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');
        $package = Package::where('type', 'composer')->where('name', $fullName)->first();
        if (! $package || ! $this->access->canAccessPackage($token, $group, $package)) {
            abort(404); // bewusst kein 403 — Existenz nicht leaken
        }

        return $package;
    }
}

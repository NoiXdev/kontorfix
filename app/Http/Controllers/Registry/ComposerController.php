<?php

namespace App\Http\Controllers\Registry;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Services\Composer\ComposerMetadataBuilder;
use App\Services\RegistryAccessService;
use App\Services\Vcs\GitRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function dist(Request $request, Group $group, string $vendor, string $name, string $version): StreamedResponse
    {
        $this->authorizeGroup($request, $group);
        $package = $this->findAccessible($request, $group, "{$vendor}/{$name}");
        $pkgVersion = $package->versions()->where('version', $version)->first();

        if ($pkgVersion === null) {
            abort(404);
        }

        $disk = Storage::disk('artifacts');
        $path = $pkgVersion->dist_path ?? "dists/{$package->id}/{$version}.zip";

        if (! $disk->exists($path)) {
            if ($package->repository_url === null) {
                abort(404);
            }
            $repo = new GitRepository($package->repository_url, $package->id);
            $repo->sync();
            $tmp = $repo->archiveZip($pkgVersion->source_reference);
            $disk->put($path, file_get_contents($tmp));
            @unlink($tmp);
            $pkgVersion->update(['dist_path' => $path]);
        }

        return response()->streamDownload(
            function () use ($disk, $path) {
                $stream = $disk->readStream($path);
                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            "{$name}-{$pkgVersion->version_pretty}.zip",
            ['Content-Type' => 'application/zip'],
        );
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

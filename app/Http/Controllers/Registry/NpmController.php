<?php

namespace App\Http\Controllers\Registry;

use App\Enums\PackageType;
use App\Exceptions\VersionConflictException;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Models\Upstream;
use App\Services\Npm\NpmMetadataBuilder;
use App\Services\Npm\NpmPublishService;
use App\Services\RegistryAccessService;
use App\Services\Upstream\NpmProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NpmController extends Controller
{
    use ResolvesRegistryPackage;

    public function __construct(
        private readonly RegistryAccessService $access,
        private readonly NpmMetadataBuilder $metadata,
        private readonly NpmPublishService $publisher,
        private readonly NpmProxyService $proxy,
    ) {}

    protected function access(): RegistryAccessService
    {
        return $this->access;
    }

    public function packument(Request $request, string $package): JsonResponse
    {
        return $this->respondPackument($request, $this->registryGroup($request), $package);
    }

    public function packumentScoped(Request $request, string $scope, string $package): JsonResponse
    {
        return $this->respondPackument($request, $this->registryGroup($request), "{$scope}/{$package}");
    }

    private function respondPackument(Request $request, Group $group, string $name): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        $this->assertProxyableName(...explode('/', $name));
        $pkg = $this->findLocal($request, $group, PackageType::Npm, $name);

        if ($pkg !== null) {
            return response()->json($this->metadata->build($pkg, $this->registryBaseUrl($request, $group)));
        }

        // If the name exists locally but isn't accessible to this group, we abort,
        // WITHOUT asking the upstream — otherwise a private package name would leak to npmjs.
        if ($this->packageExistsLocally(PackageType::Npm, $name)) {
            abort(404);
        }

        $upstream = $this->npmUpstream($group);
        if ($upstream === null) {
            abort(404);
        }

        $doc = $this->proxy->packument($group, $upstream, $name, $this->registryBaseUrl($request, $group));
        if ($doc === null) {
            abort(404);
        }

        return response()->json($doc);
    }

    private function npmUpstream(Group $group): ?Upstream
    {
        return $group->upstreams()
            ->where('type', PackageType::Npm)
            ->where('enabled', true)
            ->orderBy('priority')
            ->first();
    }

    public function tarball(Request $request, string $package, string $file): StreamedResponse
    {
        return $this->respondTarball($request, $this->registryGroup($request), $package, $file);
    }

    public function tarballScoped(Request $request, string $scope, string $package, string $file): StreamedResponse
    {
        return $this->respondTarball($request, $this->registryGroup($request), "{$scope}/{$package}", $file);
    }

    private function respondTarball(Request $request, Group $group, string $name, string $file): StreamedResponse
    {
        $this->authorizeGroup($request, $group);
        $pkg = $this->findAccessible($request, $group, PackageType::Npm, $name);
        $version = $pkg->versions()->where('dist_tarball_name', $file)->firstOrFail();

        $disk = Storage::disk('artifacts');
        abort_unless($version->dist_path !== null && $disk->exists($version->dist_path), 404);

        // Usage stats: record the download and (once) the dist size.
        if ($version->dist_size === null) {
            $version->update(['dist_size' => $disk->size($version->dist_path)]);
        }
        $version->increment('download_count');

        return response()->streamDownload(function () use ($disk, $version) {
            $stream = $disk->readStream($version->dist_path);
            if ($stream !== null) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $file, ['Content-Type' => 'application/octet-stream']);
    }

    public function publish(Request $request, string $package): JsonResponse
    {
        return $this->respondPublish($request, $this->registryGroup($request), $package);
    }

    public function publishScoped(Request $request, string $scope, string $package): JsonResponse
    {
        return $this->respondPublish($request, $this->registryGroup($request), "{$scope}/{$package}");
    }

    private function respondPublish(Request $request, Group $group, string $name): JsonResponse
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');

        // Write path: strict, org-bound authorization WITHOUT the public shortcut.
        // A publicly readable registry is not publicly writable — otherwise
        // a foreign-org publish token could smuggle in a version (finding C1).
        // Anonymous -> 401 (please authenticate); existing but unauthorized token -> 403.
        abort_if($token === null, 401, 'Authentication required for this registry.');
        abort_unless($this->access->canPublishToGroup($token, $group), 403);

        // Resolve the package strictly: it must exist AND be assigned to the target group.
        // No public shortcut via canAccessPackage() on the write path.
        $pkg = Package::where('type', PackageType::Npm)->where('name', $name)->first();
        abort_if($pkg === null || ! $this->access->packageBelongsToGroup($group, $pkg), 404);

        // A git-mirror package derives its versions from tags — publishing into it would
        // collide with the next sync, so reject it.
        abort_if($pkg->isGitSourced(), 409, 'This package mirrors a git repository and cannot be published to.');

        try {
            $this->publisher->publish($pkg, $request->json()->all());
        } catch (VersionConflictException) {
            abort(409, 'Version already exists.');
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }
}

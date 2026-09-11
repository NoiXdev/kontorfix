<?php

namespace App\Http\Controllers\Registry;

use App\Enums\PackageType;
use App\Exceptions\VersionConflictException;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Models\Upstream;
use App\Services\Licence\VersionEntitlement;
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
        private readonly VersionEntitlement $entitlement,
    ) {}

    protected function access(): RegistryAccessService
    {
        return $this->access;
    }

    public function packument(Request $request, string $package): JsonResponse
    {
        return $this->respondPackument($request, $package);
    }

    public function packumentScoped(Request $request, string $scope, string $package): JsonResponse
    {
        return $this->respondPackument($request, "{$scope}/{$package}");
    }

    private function respondPackument(Request $request, string $name): JsonResponse
    {
        /** @var Organization|null $organization */
        $organization = $request->attributes->get('registryOrganization');
        if ($organization !== null) {
            $this->authorizeOrganization($request, $organization);
            $this->assertProxyableName(...explode('/', $name));
            $pkg = $this->access->organizationPackage($organization, PackageType::Npm, $name);

            // No upstream fallthrough here (unlike the group path below): the org aggregate
            // has no single upstream to ask, and spec's error table treats "not visible to
            // the org" the same as the group path's "not accessible" — a plain 404. Mirrors
            // ComposerController::metadata()'s org branch.
            if ($pkg === null) {
                abort(404);
            }

            $windows = $this->entitlement->windowsForOrganization($organization, $pkg);

            return response()->json($this->metadata->buildForOrganization($pkg, $this->registryBaseUrlForOrganization($request, $organization), $windows));
        }

        $group = $this->registryGroup($request);
        $this->authorizeGroup($request, $group);
        $this->assertProxyableName(...explode('/', $name));
        $pkg = $this->findLocal($request, $group, PackageType::Npm, $name);

        if ($pkg !== null) {
            $bounds = $this->entitlement->boundsFor($group, $pkg);

            return response()->json($this->metadata->build($pkg, $this->registryBaseUrl($request, $group), $bounds));
        }

        // If the name exists locally but isn't accessible to this group, we abort,
        // WITHOUT asking the upstream — otherwise a private package name would leak to npmjs.
        if ($this->packageExistsLocally(PackageType::Npm, $name, $group)) {
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
        return $this->respondTarball($request, $package, $file);
    }

    public function tarballScoped(Request $request, string $scope, string $package, string $file): StreamedResponse
    {
        return $this->respondTarball($request, "{$scope}/{$package}", $file);
    }

    private function respondTarball(Request $request, string $name, string $file): StreamedResponse
    {
        /** @var Organization|null $organization */
        $organization = $request->attributes->get('registryOrganization');
        if ($organization !== null) {
            $this->authorizeOrganization($request, $organization);
            $pkg = $this->access->organizationPackage($organization, PackageType::Npm, $name);
            abort_if($pkg === null, 404);
        } else {
            $group = $this->registryGroup($request);
            $this->authorizeGroup($request, $group);
            $pkg = $this->findAccessible($request, $group, PackageType::Npm, $name);
        }

        // version_constraint is not enforced at serve time on any path today (see
        // NpmMetadataBuilder) — so there is nothing to additionally filter here either.
        // Unchanged group behavior; the org branch matches it.
        $version = $pkg->versions()->where('dist_tarball_name', $file)->firstOrFail();

        // The licence bounds ARE enforced here (a distinct check from version_constraint
        // above): a version outside the caller's window must 404 exactly like an unknown
        // version — same shape, checked BEFORE any disk access below — never served-but-
        // refused, mirroring ComposerController::dist(). findAccessible()/
        // organizationPackage() above stay unfiltered by bounds on purpose: a name licensed
        // at any window still resolves the package, only individual versions are hidden.
        $permitted = $organization !== null
            ? $this->entitlement->permitsAny(
                $this->entitlement->windowsForOrganization($organization, $pkg),
                PackageType::Npm,
                $version->version,
            )
            : $this->entitlement->permits(
                $this->entitlement->boundsFor($group, $pkg),
                PackageType::Npm,
                $version->version,
            );

        if (! $permitted) {
            abort(404);
        }

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
        $pkg = Package::where('type', PackageType::Npm)
            ->where('name', $name)
            ->where('organization_id', $group->organization_id)
            ->first();
        abort_if($pkg === null || ! $this->access->packageBelongsToGroup($group, $pkg), 404);

        // A git-mirror or foreign-registry-mirror package derives its versions from
        // somewhere else (git tags, or a MirrorSource) — publishing into it would collide
        // with the next sync, so reject it. isPublishSourced() rather than isGitSourced()
        // now that a third mode exists: both non-publish modes must be refused here, not
        // only git.
        abort_if(! $pkg->isPublishSourced(), 409, 'This package mirrors a git repository or a foreign registry and cannot be published to.');

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

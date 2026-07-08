<?php

namespace App\Http\Controllers\Registry;

use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Exceptions\VersionConflictException;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\RegistryToken;
use App\Services\Npm\NpmMetadataBuilder;
use App\Services\Npm\NpmPublishService;
use App\Services\RegistryAccessService;
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
    ) {}

    protected function access(): RegistryAccessService
    {
        return $this->access;
    }

    public function packument(Request $request, Group $group, string $package): JsonResponse
    {
        return $this->respondPackument($request, $group, $package);
    }

    public function packumentScoped(Request $request, Group $group, string $scope, string $package): JsonResponse
    {
        return $this->respondPackument($request, $group, "{$scope}/{$package}");
    }

    private function respondPackument(Request $request, Group $group, string $name): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        $pkg = $this->findAccessible($request, $group, PackageType::Npm, $name);

        return response()->json($this->metadata->build($pkg, $group->slug, $request->getSchemeAndHttpHost()));
    }

    public function tarball(Request $request, Group $group, string $package, string $file): StreamedResponse
    {
        return $this->respondTarball($request, $group, $package, $file);
    }

    public function tarballScoped(Request $request, Group $group, string $scope, string $package, string $file): StreamedResponse
    {
        return $this->respondTarball($request, $group, "{$scope}/{$package}", $file);
    }

    private function respondTarball(Request $request, Group $group, string $name, string $file): StreamedResponse
    {
        $this->authorizeGroup($request, $group);
        $pkg = $this->findAccessible($request, $group, PackageType::Npm, $name);
        $version = $pkg->versions()->where('dist_tarball_name', $file)->firstOrFail();

        $disk = Storage::disk('artifacts');
        abort_unless($version->dist_path !== null && $disk->exists($version->dist_path), 404);

        return response()->streamDownload(function () use ($disk, $version) {
            $stream = $disk->readStream($version->dist_path);
            if ($stream !== null) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $file, ['Content-Type' => 'application/octet-stream']);
    }

    public function publish(Request $request, Group $group, string $package): JsonResponse
    {
        return $this->respondPublish($request, $group, $package);
    }

    public function publishScoped(Request $request, Group $group, string $scope, string $package): JsonResponse
    {
        return $this->respondPublish($request, $group, "{$scope}/{$package}");
    }

    private function respondPublish(Request $request, Group $group, string $name): JsonResponse
    {
        $this->authorizeGroup($request, $group);

        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');
        abort_unless($token !== null && $token->ability === TokenAbility::Publish, 403);

        $pkg = $this->findAccessible($request, $group, PackageType::Npm, $name);

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

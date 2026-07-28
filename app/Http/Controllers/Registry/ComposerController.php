<?php

namespace App\Http\Controllers\Registry;

use App\Enums\PackageType;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Upstream;
use App\Services\Composer\ComposerMetadataBuilder;
use App\Services\RegistryAccessService;
use App\Services\Upstream\ComposerProxyService;
use App\Services\Vcs\GitRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComposerController extends Controller
{
    use ResolvesRegistryPackage;

    public function __construct(
        private readonly RegistryAccessService $access,
        private readonly ComposerMetadataBuilder $metadata,
        private readonly ComposerProxyService $proxy,
    ) {}

    protected function access(): RegistryAccessService
    {
        return $this->access;
    }

    public function root(Request $request): JsonResponse
    {
        $group = $this->registryGroup($request);
        $this->authorizeGroup($request, $group);
        $prefix = $this->registryPathPrefix($request, $group);

        if ($this->composerUpstream($group) !== null) {
            // With an active upstream, do NOT serve available-packages: Composer would
            // otherwise assume only the listed local packages exist, and would
            // never query upstream packages via p2 lookup.
            return response()->json([
                'metadata-url' => "{$prefix}/p2/%package%.json",
            ]);
        }

        return response()->json([
            'metadata-url' => "{$prefix}/p2/%package%.json",
            'available-packages' => $this->access->packagesFor($group)->pluck('name')->sort()->values(),
        ]);
    }

    public function metadata(Request $request, string $vendor, string $name): JsonResponse
    {
        $group = $this->registryGroup($request);
        $this->authorizeGroup($request, $group);
        $fullName = "{$vendor}/{$name}";
        $package = $this->findLocal($request, $group, PackageType::Composer, $fullName);

        if ($package !== null) {
            return response()->json($this->metadata->build($package, $group, $this->registryBaseUrl($request, $group)));
        }

        // If the name exists locally but isn't accessible to this group, we abort,
        // WITHOUT asking the upstream — otherwise a private package name would leak to packagist.
        if ($this->packageExistsLocally(PackageType::Composer, $fullName)) {
            abort(404);
        }

        $upstream = $this->composerUpstream($group);
        if ($upstream === null) {
            abort(404);
        }

        $doc = $this->proxy->metadata($group, $upstream, $fullName, $this->registryBaseUrl($request, $group));
        if ($doc === null) {
            abort(404);
        }

        return response()->json($doc);
    }

    private function composerUpstream(Group $group): ?Upstream
    {
        return $group->upstreams()
            ->where('type', PackageType::Composer)
            ->where('enabled', true)
            ->orderBy('priority')
            ->first();
    }

    public function dist(Request $request, string $vendor, string $name, string $version): StreamedResponse
    {
        $group = $this->registryGroup($request);
        $this->authorizeGroup($request, $group);
        $package = $this->findAccessible($request, $group, PackageType::Composer, "{$vendor}/{$name}");
        $pkgVersion = $package->versions()->where('version', $version)->first();

        if ($pkgVersion === null) {
            abort(404);
        }

        $disk = Storage::disk('artifacts');
        // Keyed by commit SHA: a force-push changes source_reference and thus
        // the path — the old archive is never mistakenly served again (cache invalidation).
        $path = "dists/{$package->id}/{$pkgVersion->source_reference}.zip";

        if (! $disk->exists($path)) {
            if ($package->repository_url === null) {
                abort(404);
            }

            $repo = new GitRepository($package->repository_url, $package->id);
            $repo->sync();
            $tmp = $repo->archiveZip($pkgVersion->source_reference);

            // Atomic: stream into a unique temp path, then move to the
            // final path via rename — prevents torn reads on concurrent requests.
            $staging = "dists/{$package->id}/.{$pkgVersion->source_reference}.".uniqid().'.part';
            try {
                $handle = fopen($tmp, 'r');
                $disk->writeStream($staging, $handle);
                if (is_resource($handle)) {
                    fclose($handle);
                }
            } finally {
                @unlink($tmp);
            }
            $disk->move($staging, $path);

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
}

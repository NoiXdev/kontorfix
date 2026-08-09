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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $this->assertProxyableName($vendor, $name);
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

    /**
     * Re-read on every call: between the outer check and the one inside the lock, another
     * request may have finished the build. That is the entire point of the lock, so the
     * second read must not be folded into the first.
     *
     * @phpstan-impure
     */
    private function distExists(Filesystem $disk, string $path): bool
    {
        return $disk->exists($path);
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

        if (! $this->distExists($disk, $path)) {
            if ($package->repository_url === null) {
                abort(404);
            }

            // One builder per dist. The first request for a version clones the repository
            // and builds the archive; without a lock, N concurrent requests for the same
            // uncached version each run their own clone and their own zip, which is an
            // amplification any read-token holder (anonymous, on a public registry) can
            // drive by asking for a cold version in parallel.
            //
            // Waiting is strictly cheaper than the duplicate build it replaces, and it is
            // bounded: if the lock cannot be had in time we build anyway, i.e. we fall back
            // to exactly the previous behaviour rather than refusing the download.
            $lock = Cache::lock('dist-build:'.$path, 300);
            $held = false;

            try {
                $lock->block((int) config('kontorfix.dist_build_lock_wait', 15));
                $held = true;
            } catch (LockTimeoutException) {
                // Fall through and build unlocked.
            }

            try {
                // The waiting request usually finds the archive already there.
                if (! $this->distExists($disk, $path)) {
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
            } finally {
                if ($held) {
                    $lock->release();
                }
            }
        }

        // Usage stats: record the download and (once) the dist size.
        if ($pkgVersion->dist_size === null) {
            $pkgVersion->update(['dist_size' => $disk->size($path)]);
        }
        $pkgVersion->increment('download_count');

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

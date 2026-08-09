<?php

namespace App\Http\Controllers\Registry;

use App\Enums\PackageType;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Upstream;
use App\Services\RegistryAccessService;
use App\Services\Upstream\ComposerProxyService;
use App\Services\Upstream\NpmProxyService;
use App\Services\Upstream\UpstreamCache;
use App\Services\Upstream\UpstreamClient;
use App\Services\Upstream\UrlSafety;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProxyDownloadController extends Controller
{
    use ResolvesRegistryPackage;

    public function __construct(
        private readonly RegistryAccessService $access,
        private readonly UpstreamClient $client,
        private readonly UpstreamCache $cache,
        private readonly ComposerProxyService $composerProxy,
        private readonly NpmProxyService $npmProxy,
    ) {}

    protected function access(): RegistryAccessService
    {
        return $this->access;
    }

    public function composer(Request $request, string $upstream, string $vendor, string $name, string $version): StreamedResponse
    {
        $group = $this->registryGroup($request);
        $this->authorizeGroup($request, $group);
        $packageName = "{$vendor}/{$name}";
        $up = $this->resolveUpstream($upstream, $group, PackageType::Composer, $packageName);

        // Defense in depth behind the route constraint: the storage key is built by
        // interpolation right below, so the invariant is asserted where it is relied on
        // rather than only in routes/registry.php, where a later edit could widen it back.
        $this->assertSafeKeySegments($vendor, $name, $version);

        $path = "proxy/{$up->id}/composer/{$vendor}/{$name}/{$version}.zip";

        // SSRF protection: the URL actually fetched comes EXCLUSIVELY from the cached
        // upstream payload (dist.url), never from the client request. Version and package
        // name from the route only serve to SELECT the matching cache entry — not to
        // construct an arbitrary URL. Resolved lazily, so a cache hit never touches it.
        return $this->serveArtifact(
            $up,
            $path,
            fn (): ?string => $this->resolveComposerDistUrl($request, $group, $up, $packageName, $version),
            "{$name}-{$version}.zip",
            'application/zip',
        );
    }

    public function npm(Request $request, string $upstream, string $package, string $file): StreamedResponse
    {
        return $this->respondNpm($request, $this->registryGroup($request), $upstream, $package, $file);
    }

    public function npmScoped(Request $request, string $upstream, string $scope, string $package, string $file): StreamedResponse
    {
        return $this->respondNpm($request, $this->registryGroup($request), $upstream, "{$scope}/{$package}", $file);
    }

    private function respondNpm(Request $request, Group $group, string $upstream, string $packageName, string $file): StreamedResponse
    {
        $this->authorizeGroup($request, $group);
        $up = $this->resolveUpstream($upstream, $group, PackageType::Npm, $packageName);

        // The npm route parameters are charset-constrained already; keep the same
        // explicit check so the two proxy paths cannot drift apart.
        $this->assertSafeKeySegments(...[...explode('/', $packageName), $file]);

        $path = "proxy/{$up->id}/npm/{$packageName}/{$file}";

        // SSRF protection: the URL to fetch is EXCLUSIVELY dist.tarball from the cached
        // packument — the client filename only serves to select the matching version entry,
        // never to construct the target URL.
        return $this->serveArtifact(
            $up,
            $path,
            fn (): ?string => $this->resolveNpmTarballUrl($request, $group, $up, $packageName, $file),
            $file,
            'application/octet-stream',
        );
    }

    /**
     * Serves a proxied artifact from the cache, or fetches it from the upstream once.
     *
     * The lock is the point. `relayArtifact()` declines the local copy for an artifact over
     * the per-artifact cap, and keeps serving — deliberately, so a storage policy never
     * becomes an install failure. But `hasArtifact()` then stays false for that coordinate,
     * so N concurrent requests for it were N concurrent full upstream fetches and N full
     * relays, at a rate the caller chooses, with no request budget in front of the registry
     * routes. (There is none by design: one `composer install` fires hundreds of metadata
     * requests from one address, so a request budget low enough to matter breaks CI.) What
     * needed bounding was duplicated *work*, not requests — the same amplifier, and the same
     * remedy, as the `dist-build:` lock on the git-sourced path.
     *
     * Three properties, all of which matter more than the lock:
     *
     *  - the download is never refused. The wait is bounded and a timeout falls through to
     *    an unlocked fetch, i.e. to exactly the behaviour that predates the lock. A large
     *    legitimate artifact under contention is slower, never a 4xx.
     *  - the waiter usually pays nothing: it re-checks the cache after acquiring, and the
     *    holder has normally just filled it. That is the collapse.
     *  - a lock orphaned by a client disconnect mid-relay expires on its own TTL, so a
     *    dropped connection cannot wedge a coordinate.
     *
     * @param  callable(): ?string  $resolveUrl  the upstream URL, resolved only on a miss
     */
    private function serveArtifact(Upstream $up, string $path, callable $resolveUrl, string $filename, string $contentType): StreamedResponse
    {
        $disk = Storage::disk('artifacts');

        if ($this->cache->hasArtifact($path)) {
            return $this->streamFromDisk($disk, $path, $filename, $contentType);
        }

        $lock = Cache::lock('upstream-fetch:'.$path, (int) config('kontorfix.upstream_fetch_lock_ttl', 300));
        $held = false;

        try {
            $lock->block((int) config('kontorfix.upstream_fetch_lock_wait', 15));
            $held = true;
        } catch (LockTimeoutException) {
            // Fall through and fetch unlocked rather than refuse the download.
        }

        try {
            // The waiting request usually finds the artifact already cached.
            if ($held && $this->cache->hasArtifact($path)) {
                return $this->streamFromDisk($disk, $path, $filename, $contentType);
            }

            $source = $this->client->getStream($up, $this->assertSafeArtifactUrl($resolveUrl()));
            abort_if($source === null, 404);
        } catch (Throwable $e) {
            if ($held) {
                $lock->release();
            }

            throw $e;
        }

        if (! $held) {
            $lock = null;
        }

        // Relayed to the client while it is cached, so the size cap is applied to the bytes
        // as they arrive rather than to a string that had to fit in memory first. An artifact
        // over the cap is still delivered; only the caching is declined. The upstream's
        // declared length travels along so a transfer that ends short is relayed to this
        // caller but never committed to the cache key.
        return response()->streamDownload(
            function () use ($source, $path, $lock) {
                try {
                    $this->cache->relayArtifact($source['stream'], $path, $source['length']);
                } finally {
                    $lock?->release();
                }
            },
            $filename,
            ['Content-Type' => $contentType],
        );
    }

    private function streamFromDisk(Filesystem $disk, string $path, string $filename, string $contentType): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($disk, $path) {
                $stream = $disk->readStream($path);
                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            $filename,
            ['Content-Type' => $contentType],
        );
    }

    /**
     * Refuses anything that would not stay a single directory level once the storage key
     * is normalised. 404 rather than 422: the escaped key names an object the caller has
     * no business knowing about, and an error that distinguished "escaped" from "absent"
     * would be a probe for what lives on the disk.
     */
    private function assertSafeKeySegments(string ...$segments): void
    {
        foreach ($segments as $segment) {
            abort_unless(UpstreamCache::isSafeKeySegment($segment), 404);
        }
    }

    private function resolveUpstream(string $upstreamId, Group $group, PackageType $type, string $packageName): Upstream
    {
        // The route pattern already refuses a non-UUID, but this lookup is a raw find()
        // against a Postgres `uuid` column: anything malformed that ever reaches it
        // raises SQLSTATE[22P02], which nothing renders, so the caller gets a 500 and
        // the instance an unthrottled stack trace per request. Do not make that depend
        // on a regex in a different file staying correct.
        abort_unless(Str::isUuid($upstreamId), 404);

        $up = Upstream::find($upstreamId);
        abort_if($up === null || $up->group_id !== $group->id || $up->type !== $type || ! $up->enabled, 404);

        // Strict mode also applies to the artifact download — otherwise the artifact
        // of a non-approved package could be loaded via an old cache entry, even though
        // the metadata already returns 404 (dependency confusion protection).
        abort_unless($up->allowsPackage($packageName), 404);

        return $up;
    }

    /**
     * Prevents second-order SSRF: a dist URL supplied by the (possibly compromised)
     * upstream must not be file://, gopher:// or similar, and must not point to
     * internal/reserved addresses. Dist URLs legitimately point to CDNs (GitHub, npm),
     * so there's no host-equality check against the upstream, but there is a scheme and
     * address-range check — including DNS resolution, so that an internally resolving
     * hostname or an octal/decimal-encoded private IP is also rejected.
     */
    private function assertSafeArtifactUrl(?string $url): string
    {
        abort_if($url === null, 404);
        abort_unless(UrlSafety::isSafeResolving($url), 422, 'Unsafe upstream artifact URL.');

        return $url;
    }

    private function resolveComposerDistUrl(Request $request, Group $group, Upstream $up, string $packageName, string $version): ?string
    {
        $payload = $this->cache->getMetadata($up, $packageName);
        if ($payload === null) {
            // Cache expired/empty: re-fetch metadata via the proxy service (this
            // populates the cache as a side effect), then read the raw cache again.
            $this->composerProxy->metadata($group, $up, $packageName, $this->registryBaseUrl($request, $group));
            $payload = $this->cache->getMetadata($up, $packageName);
        }

        if ($payload === null) {
            return null;
        }

        $minifiedVersions = $payload['packages'][$packageName] ?? [];
        $versions = MetadataMinifier::expand($minifiedVersions);

        foreach ($versions as $v) {
            $identifier = $v['version_normalized'] ?? $v['version'] ?? null;
            if ($identifier === $version && isset($v['dist']['url'])) {
                return $v['dist']['url'];
            }
        }

        return null;
    }

    private function resolveNpmTarballUrl(Request $request, Group $group, Upstream $up, string $packageName, string $file): ?string
    {
        $payload = $this->cache->getMetadata($up, $packageName);
        if ($payload === null) {
            $this->npmProxy->packument($group, $up, $packageName, $this->registryBaseUrl($request, $group));
            $payload = $this->cache->getMetadata($up, $packageName);
        }

        if ($payload === null) {
            return null;
        }

        $versions = $payload['versions'] ?? [];
        foreach ($versions as $v) {
            $tarball = $v['dist']['tarball'] ?? null;
            if ($tarball === null) {
                continue;
            }
            if (basename((string) parse_url($tarball, PHP_URL_PATH)) === $file) {
                return $tarball;
            }
        }

        return null;
    }
}

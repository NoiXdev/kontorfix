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
use App\Services\Vcs\MirrorLockBusy;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

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
        if ($this->packageExistsLocally(PackageType::Composer, $fullName, $group)) {
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
            //
            // The TTL has to cover everything the holder does while holding it: waiting for
            // the mirror lock (kontorfix.mirror_lock_wait_web — the *web* budget, because
            // that is what this caller passes to sync() below), then in the worst case a
            // full clone (GitRepository::WORST_CASE_WORK), then `git archive`
            // (GitRepository::COMMAND_TIMEOUT), and finally streaming the result onto the
            // artifacts disk. Only the last of those has no timeout of its own — it is a
            // copy of a file `git archive` has already produced, so a second COMMAND_TIMEOUT
            // is the honest order-of-magnitude allowance rather than a bound.
            //
            // A TTL shorter than that lapses under a live builder and lets a waiter start a
            // second build of the same dist — harmless (the archive is staged under a unique
            // name and renamed) but exactly the duplicate work this lock exists to prevent.
            // It costs nothing when a builder dies: waiters fall through after
            // kontorfix.dist_build_lock_wait regardless of the TTL.
            $mirrorWait = (int) config('kontorfix.mirror_lock_wait_web', GitRepository::DEFAULT_WEB_LOCK_WAIT);

            $lock = Cache::lock(
                'dist-build:'.$path,
                $mirrorWait + GitRepository::WORST_CASE_WORK + 2 * GitRepository::COMMAND_TIMEOUT,
            );
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
                    // The web budget, not the queue's. A request blocked here holds a
                    // FrankenPHP thread, and that pool also serves /up — see
                    // GitRepository::sync() and config/kontorfix.php.
                    try {
                        $repo->sync($mirrorWait);
                    } catch (MirrorLockBusy $e) {
                        // 503, not 500: the package is fine and the archive will exist
                        // shortly, so the honest answer is "temporarily unavailable, come
                        // back" with a hint of when. A 500 says the request can never
                        // succeed, which is what this path used to claim.
                        //
                        // What the status code buys, regardless of what any particular
                        // client does with it: correct semantics, a signal a proxy or a
                        // monitoring system can act on without parsing a German sentence,
                        // and — the part that actually matters for this codebase — a
                        // FrankenPHP thread released now instead of held for the queue's
                        // much longer wait. Retry-After is set to four times the wait we
                        // just spent on the same logic: it is not a prediction of when the
                        // holder finishes (not knowable from here), it is what keeps a
                        // client that DOES honour the header from immediately re-blocking
                        // another thread against the same busy clone.
                        //
                        // What it does NOT buy, checked against Composer 2.10's own
                        // source rather than assumed: Composer never reads Retry-After —
                        // there is no reference to it anywhere in its codebase — and its
                        // cURL downloader retries 423, 425, 500, 502, 503, 504, 507 and 510
                        // identically (CurlDownloader::isAuthenticatedRetryStatusCode() and
                        // friends), with a fixed backoff of 0ms/100ms/500ms over 3 retries.
                        // A 503 here gets exactly the treatment the 500 it replaces used to
                        // get. For sustained contention that means a `composer install`
                        // still exhausts its retries and fails outright — for *clone*
                        // contention (parallel cold versions of one package, this branch's
                        // original motivating case) that is the pre-branch v0.7.0 outcome
                        // again, not a fix for it. The common case, contention against a
                        // *fetch*, is unaffected: it is absorbed by $mirrorWait before this
                        // is ever thrown. Composer's indifference is not a reason to send
                        // 500 instead — the status code is correct on its own terms, and
                        // other clients (browsers, proxies, CDNs) do honour the header —
                        // but it is a reason not to claim this closes the parallel-install
                        // gap for the client that actually matters here.
                        //
                        // Logged explicitly because ServiceUnavailableHttpException is an
                        // HttpException, and Laravel's exception handler treats every
                        // HttpException as reportable-by-default-false ($internalDontReport)
                        // — the 500 this replaces WAS reported. Without this line, mirror
                        // contention on the web path is invisible: exactly the signal that
                        // would show a saturating thread pool before it saturates.
                        Log::info('Mirror lock busy on the web path; answering 503.', [
                            'package_id' => $package->id,
                            'version' => $version,
                            'wait_seconds' => $mirrorWait,
                        ]);

                        throw new ServiceUnavailableHttpException(max($mirrorWait, 1) * 4, $e->getMessage(), $e);
                    }
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

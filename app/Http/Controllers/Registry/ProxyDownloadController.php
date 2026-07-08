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
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function composer(Request $request, Group $group, string $upstream, string $vendor, string $name, string $version): StreamedResponse
    {
        $this->authorizeGroup($request, $group);
        $up = $this->resolveUpstream($upstream, $group, PackageType::Composer);

        $packageName = "{$vendor}/{$name}";
        $path = "proxy/{$up->id}/composer/{$vendor}/{$name}/{$version}.zip";
        $disk = Storage::disk('artifacts');

        if (! $this->cache->hasArtifact($path)) {
            // SSRF-Schutz: die tatsächlich abzurufende URL kommt AUSSCHLIESSLICH aus dem
            // gecachten Upstream-Payload (dist.url), niemals aus der Client-Anfrage. Version
            // und Paketname aus der Route dienen nur dazu, den passenden Cache-Eintrag zu
            // SELEKTIEREN — nicht dazu, eine beliebige URL zu konstruieren.
            $originalUrl = $this->resolveComposerDistUrl($request, $group, $up, $packageName, $version);
            abort_if($originalUrl === null, 404);

            $bytes = $this->client->getBytes($up, $originalUrl);
            abort_if($bytes === null, 404);

            $this->cache->putArtifact($path, $bytes);
        }

        return response()->streamDownload(
            function () use ($disk, $path) {
                $stream = $disk->readStream($path);
                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            "{$name}-{$version}.zip",
            ['Content-Type' => 'application/zip'],
        );
    }

    public function npm(Request $request, Group $group, string $upstream, string $package, string $file): StreamedResponse
    {
        return $this->respondNpm($request, $group, $upstream, $package, $file);
    }

    public function npmScoped(Request $request, Group $group, string $upstream, string $scope, string $package, string $file): StreamedResponse
    {
        return $this->respondNpm($request, $group, $upstream, "{$scope}/{$package}", $file);
    }

    private function respondNpm(Request $request, Group $group, string $upstream, string $packageName, string $file): StreamedResponse
    {
        $this->authorizeGroup($request, $group);
        $up = $this->resolveUpstream($upstream, $group, PackageType::Npm);

        $path = "proxy/{$up->id}/npm/{$packageName}/{$file}";
        $disk = Storage::disk('artifacts');

        if (! $this->cache->hasArtifact($path)) {
            // SSRF-Schutz: die abzurufende URL ist AUSSCHLIESSLICH dist.tarball aus dem
            // gecachten Packument — der Client-Dateiname dient nur zur Auswahl des
            // passenden Versions-Eintrags, nie zur Konstruktion der Ziel-URL.
            $originalUrl = $this->resolveNpmTarballUrl($request, $group, $up, $packageName, $file);
            abort_if($originalUrl === null, 404);

            $bytes = $this->client->getBytes($up, $originalUrl);
            abort_if($bytes === null, 404);

            $this->cache->putArtifact($path, $bytes);
        }

        return response()->streamDownload(
            function () use ($disk, $path) {
                $stream = $disk->readStream($path);
                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            $file,
            ['Content-Type' => 'application/octet-stream'],
        );
    }

    private function resolveUpstream(string $upstreamId, Group $group, PackageType $type): Upstream
    {
        $up = Upstream::find($upstreamId);
        abort_if($up === null || $up->group_id !== $group->id || $up->type !== $type, 404);

        return $up;
    }

    private function resolveComposerDistUrl(Request $request, Group $group, Upstream $up, string $packageName, string $version): ?string
    {
        $payload = $this->cache->getMetadata($up, $packageName);
        if ($payload === null) {
            // Cache abgelaufen/leer: Metadaten über den Proxy-Service neu holen (dies
            // befüllt den Cache als Seiteneffekt), danach den Rohcache erneut lesen.
            $this->composerProxy->metadata($group, $up, $packageName, $request->getSchemeAndHttpHost());
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
            $this->npmProxy->packument($group, $up, $packageName, $request->getSchemeAndHttpHost());
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

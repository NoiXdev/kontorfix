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

    public function root(Request $request, Group $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);

        if ($this->composerUpstream($group) !== null) {
            // Bei aktivem Upstream KEIN available-packages ausliefern: Composer würde
            // sonst annehmen, es gäbe ausschließlich die gelisteten lokalen Pakete, und
            // fragt Upstream-Pakete nie per p2-Lookup an.
            return response()->json([
                'metadata-url' => "/r/{$group->slug}/p2/%package%.json",
            ]);
        }

        return response()->json([
            'metadata-url' => "/r/{$group->slug}/p2/%package%.json",
            'available-packages' => $this->access->packagesFor($group)->pluck('name')->sort()->values(),
        ]);
    }

    public function metadata(Request $request, Group $group, string $vendor, string $name): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        $fullName = "{$vendor}/{$name}";
        $package = $this->findLocal($request, $group, PackageType::Composer, $fullName);

        if ($package !== null) {
            return response()->json($this->metadata->build($package, $group, $request->getSchemeAndHttpHost()));
        }

        $upstream = $this->composerUpstream($group);
        if ($upstream === null) {
            abort(404);
        }

        $doc = $this->proxy->metadata($group, $upstream, $fullName, $request->getSchemeAndHttpHost());
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

    public function dist(Request $request, Group $group, string $vendor, string $name, string $version): StreamedResponse
    {
        $this->authorizeGroup($request, $group);
        $package = $this->findAccessible($request, $group, PackageType::Composer, "{$vendor}/{$name}");
        $pkgVersion = $package->versions()->where('version', $version)->first();

        if ($pkgVersion === null) {
            abort(404);
        }

        $disk = Storage::disk('artifacts');
        // Nach Commit-SHA geschlüsselt: ein Force-Push ändert source_reference und damit
        // den Pfad — das alte Archiv wird nie fälschlich weitergeliefert (Cache-Invalidierung).
        $path = "dists/{$package->id}/{$pkgVersion->source_reference}.zip";

        if (! $disk->exists($path)) {
            if ($package->repository_url === null) {
                abort(404);
            }

            $repo = new GitRepository($package->repository_url, $package->id);
            $repo->sync();
            $tmp = $repo->archiveZip($pkgVersion->source_reference);

            // Atomar: in einen eindeutigen Temp-Pfad streamen, dann per rename an den
            // endgültigen Pfad verschieben — verhindert Torn Reads bei parallelen Requests.
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

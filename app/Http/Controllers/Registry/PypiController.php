<?php

namespace App\Http\Controllers\Registry;

use App\Enums\PackageType;
use App\Exceptions\VersionConflictException;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Services\Python\PythonName;
use App\Services\Python\PythonPublishService;
use App\Services\Python\PythonSimpleIndexBuilder;
use App\Services\RegistryAccessService;
use App\Support\CredentialUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PyPI-compatible registry: twine upload (distutils "file_upload"), the PEP 503 / PEP 691
 * "simple" repository API that pip consumes, and file downloads. Serving is scoped to the
 * resolved group; unknown projects fall through to a configured Python upstream.
 */
class PypiController extends Controller
{
    use ResolvesRegistryPackage;

    private const JSON_ACCEPT = 'application/vnd.pypi.simple.v1+json';

    public function __construct(
        private readonly RegistryAccessService $access,
        private readonly PythonPublishService $publisher,
        private readonly PythonSimpleIndexBuilder $builder,
    ) {}

    protected function access(): RegistryAccessService
    {
        return $this->access;
    }

    /** twine upload — multipart POST to the registry root. */
    public function upload(Request $request): Response
    {
        $group = $this->registryGroup($request);

        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');
        abort_if($token === null, 401, 'Authentication required for this registry.');
        abort_unless($this->access->canPublishToGroup($token, $group), 403);

        $normalized = PythonName::normalize((string) $request->input('name', ''));
        $pkg = $this->pythonPackagesOfGroup($group)
            ->first(fn (Package $p): bool => PythonName::normalize($p->name) === $normalized);
        abort_if($pkg === null, 404, 'Unknown project for this registry.');
        // A git-mirror project derives its files from tags — reject uploads into it.
        abort_if($pkg->isGitSourced(), 409, 'This project mirrors a git repository and cannot be uploaded to.');

        $file = $request->file('content');
        abort_if(! $file instanceof UploadedFile || ! $file->isValid(), 400, 'Missing distribution file.');

        try {
            $this->publisher->publish($pkg, $file, $request->all());
        } catch (VersionConflictException) {
            abort(409, 'This distribution file already exists.');
        } catch (InvalidArgumentException $e) {
            abort(400, $e->getMessage());
        }

        return response('', 200);
    }

    /** Root simple index: every Python project readable in this registry. */
    public function simpleRoot(Request $request): Response
    {
        $group = $this->registryGroup($request);
        $this->authorizeGroup($request, $group);

        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');
        $names = $this->pythonPackagesOfGroup($group)
            ->filter(fn (Package $p): bool => $this->access->canAccessPackage($token, $group, $p))
            ->map(fn (Package $p): string => PythonName::normalize($p->name))
            ->values();

        return response(
            $this->builder->rootHtml($names, $this->registryBaseUrl($request, $group)),
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    /** Project detail page (files), or a redirect to the upstream for unknown projects. */
    public function simpleProject(Request $request, string $project): Response|RedirectResponse|JsonResponse
    {
        $group = $this->registryGroup($request);
        $this->authorizeGroup($request, $group);

        // The third ecosystem, closed to match the other two. `[A-Za-z0-9._-]+` admits `.`
        // and `..`, neither of which can name a project. Measured before this line: PEP 503
        // normalisation already collapsed them to `-`, so the outbound path was
        // `/simple/-/` rather than a traversal — the impact the sibling routes had does not
        // reach here. Refused anyway, and BEFORE normalisation, so that the guarantee rests
        // on an explicit refusal rather than on a side effect of the normaliser.
        $this->assertProxyableName($project);

        $normalized = PythonName::normalize($project);

        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');
        $pkg = $this->pythonPackagesOfGroup($group)
            ->first(fn (Package $p): bool => PythonName::normalize($p->name) === $normalized
                && $this->access->canAccessPackage($token, $group, $p));

        if ($pkg !== null) {
            $dists = $pkg->pythonDists()->orderBy('filename')->get();
            $base = $this->registryBaseUrl($request, $group);

            if (str_contains((string) $request->header('Accept'), self::JSON_ACCEPT)) {
                return response()->json($this->builder->projectJson($pkg, $dists, $base))
                    ->header('Content-Type', self::JSON_ACCEPT);
            }

            return response($this->builder->projectHtml($pkg, $dists, $base), 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        // A private name that exists locally must never be forwarded upstream (dependency
        // confusion protection) — mirror the Composer/npm behaviour.
        if ($this->pythonExistsLocally($normalized, $group)) {
            abort(404);
        }

        $upstream = $group->upstreams()
            ->where('type', PackageType::Python)
            ->where('enabled', true)
            ->orderBy('priority')
            ->first();

        if ($upstream !== null) {
            // A 302 hands its `Location` to the client, and this endpoint is readable by
            // a pull token — or, on a public group, by nobody at all. `upstreams.url` is
            // the only place a Basic-auth mirror credential can live (UpstreamClient
            // sends the dedicated `auth_token` as a Bearer header and nothing else), so
            // concatenating it into a redirect published the mirror's password in
            // cleartext to the lowest tier the product has, and onward into every CI log
            // and proxy on the path.
            //
            // Redaction is not the fix here: `***@host` is a credential the client would
            // dial, and it still says a credential exists. The credential is removed —
            // and where one exists at all, no redirect is emitted. Sending pip to a
            // private mirror unauthenticated only trades the disclosure for a 401 while
            // still naming the internal host and the project being resolved. The
            // condition is reported on the operator health page (HealthService) rather
            // than failing silently.
            if (CredentialUrl::carries($upstream->url)) {
                Log::warning('PyPI simple-index fallthrough declined: upstream URL carries a credential.', [
                    'upstream_id' => $upstream->id,
                    'group_id' => $group->id,
                ]);

                abort(404);
            }

            $target = rtrim((string) CredentialUrl::strip($upstream->url), '/');

            return redirect()->away($target.'/simple/'.$normalized.'/', 302);
        }

        abort(404);
    }

    /** Stream a stored distribution file. */
    public function download(Request $request, string $package, string $filename): StreamedResponse
    {
        $group = $this->registryGroup($request);
        $this->authorizeGroup($request, $group);

        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');

        // Same reasoning as ProxyDownloadController::resolveUpstream(): whereKey() on a
        // Postgres `uuid` column raises SQLSTATE[22P02] for anything that is not one,
        // and an unrendered QueryException is a 500 plus a logged stack trace. The route
        // pattern refuses those already; this does not rely on it.
        abort_unless(Str::isUuid($package), 404);

        // Scoped like every other read path. A UUID cannot collide across organizations the
        // way a name can, so this is not closing a guessing attack — it closes the same
        // cross-organization pivot row the index and project page now refuse, which would
        // otherwise still stream its distributions through the foreign registry.
        $pkg = Package::where('type', PackageType::Python)
            ->where('organization_id', $group->organization_id)
            ->whereKey($package)
            ->first();
        abort_if($pkg === null || ! $this->access->canAccessPackage($token, $group, $pkg), 404);

        $dist = $pkg->pythonDists()->where('filename', $filename)->firstOrFail();

        $disk = Storage::disk('artifacts');
        abort_unless($disk->exists($dist->path), 404);

        $dist->increment('download_count');

        return response()->streamDownload(function () use ($disk, $dist) {
            $stream = $disk->readStream($dist->path);
            if ($stream !== null) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $filename, ['Content-Type' => 'application/octet-stream']);
    }

    /**
     * Python packages assigned to the group (unfiltered by access — callers refine).
     *
     * Constrained to the owning organization for the same reason findLocal() is: the pivot
     * row records assignment, and canAccessPackage() checks assignment and group access —
     * neither compares the package's organization to the registry's. A cross-organization
     * pivot row would therefore be served here. The enforcement migration now refuses to
     * complete while such a row exists, so this constraint should never match anything;
     * it is stated anyway, because this is the only ecosystem where ownership was left
     * implied by the access check rather than written into the query, and an invariant
     * that only one of three read paths spells out is one edit from being lost.
     *
     * @return Collection<int, Package>
     */
    private function pythonPackagesOfGroup(Group $group): Collection
    {
        return $group->packages()
            ->where('type', PackageType::Python)
            ->where('packages.organization_id', $group->organization_id)
            ->get();
    }

    /**
     * The Python half of the dependency-confusion guard, scoped to the addressed
     * organization for the reasons given on ResolvesRegistryPackage::packageExistsLocally().
     */
    private function pythonExistsLocally(string $normalized, Group $group): bool
    {
        return Package::where('type', PackageType::Python)
            ->where('organization_id', $group->organization_id)
            ->get()
            ->contains(fn (Package $p): bool => PythonName::normalize($p->name) === $normalized);
    }
}

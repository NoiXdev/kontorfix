<?php

namespace App\Http\Controllers\Registry\Oci;

use App\Enums\PackageType;
use App\Exceptions\OciException;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\OciBlob;
use App\Models\OciBlobUpload;
use App\Models\Package;
use App\Services\Oci\BlobStore;
use App\Services\Oci\Digest;
use App\Services\RegistryAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The blob upload protocol: `docker push` spends most of its time here, moving one layer
 * per PATCH-or-POST/PUT dance. Chunked and monolithic uploads share the same underlying
 * BlobStore calls — a monolithic push is simply begin() + append() + finish() inside one
 * request instead of spread across several.
 */
class BlobController extends Controller
{
    use ResolvesOciRepository;

    public function __construct(
        private readonly RegistryAccessService $access,
        private readonly BlobStore $blobs,
    ) {}

    protected function access(): RegistryAccessService
    {
        return $this->access;
    }

    /**
     * POST /v2/{name}/blobs/uploads/ — handles three cases, in this order:
     *
     *   1. `?mount=<digest>&from=<name>` — attach an existing blob without transferring it.
     *      Answered without ever opening an upload session. A miss here (unknown source
     *      repository, or one in another organization) is not an error: it is the
     *      protocol's own defined fallback, and falls through to case 3 rather than
     *      leaking whether the named source repository or digest exists.
     *   2. `?digest=<digest>` with a body — a monolithic upload: the whole blob arrives in
     *      this one request.
     *   3. Neither — opens a chunked upload session for the client to PATCH into.
     */
    public function begin(Request $request, string $name): Response
    {
        $group = $this->ociGroup($request);
        $package = $this->ociWritableRepository($request, $group, $name);

        $mount = $request->query('mount');
        $from = $request->query('from');

        if (is_string($mount) && $mount !== '' && is_string($from) && $from !== '') {
            $mounted = $this->mountFrom($group, $package, $mount, $from);

            if ($mounted !== null) {
                return $this->blobCompletedResponse($name, $mounted);
            }
        }

        $digest = $request->query('digest');

        if (is_string($digest) && $digest !== '') {
            Digest::assertValid($digest);

            $upload = $this->blobs->begin($package);
            $this->blobs->append($upload, $request->getContent(asResource: true));
            $blob = $this->blobs->finish($upload, $digest);

            return $this->blobCompletedResponse($name, $blob);
        }

        $upload = $this->blobs->begin($package);

        return $this->sessionResponse($name, $upload);
    }

    /** PATCH /v2/{name}/blobs/uploads/{uploadId} — appends one chunk. */
    public function append(Request $request, string $name, string $uploadId): Response
    {
        $group = $this->ociGroup($request);
        $package = $this->ociWritableRepository($request, $group, $name);
        $upload = $this->findUpload($package, $uploadId);

        // append() mutates $upload's own `offset` attribute via update() before returning,
        // so the model already reflects the new offset here — no extra query needed.
        $this->blobs->append($upload, $request->getContent(asResource: true));

        return $this->sessionResponse($name, $upload);
    }

    /** PUT /v2/{name}/blobs/uploads/{uploadId}?digest=... — the final chunk (optional) plus verification. */
    public function finish(Request $request, string $name, string $uploadId): Response
    {
        $group = $this->ociGroup($request);
        $package = $this->ociWritableRepository($request, $group, $name);
        $upload = $this->findUpload($package, $uploadId);

        $digest = (string) $request->query('digest');
        Digest::assertValid($digest);

        // The client MAY attach a final chunk to this request. append() treats an empty
        // body the same as any other chunk (it appends zero bytes), so there is no need to
        // special-case "nothing left to send" here.
        $this->blobs->append($upload, $request->getContent(asResource: true));

        $blob = $this->blobs->finish($upload, $digest);

        return $this->blobCompletedResponse($name, $blob);
    }

    /**
     * GET/HEAD /v2/{name}/blobs/{digest} — HEAD only in this task. Task 6 gives GET a body
     * and the storage-backend streaming branch; this method never inspects the request
     * method for that reason.
     */
    public function show(Request $request, string $name, string $digest): Response
    {
        $group = $this->ociGroup($request);
        $package = $this->ociRepository($request, $group, $name);

        // Scoped to this package's own organization — never by digest alone — so a token
        // cannot use this endpoint to probe whether some OTHER tenant holds a given layer.
        $blob = $this->blobs->find((string) $package->organization_id, $digest);

        if ($blob === null) {
            throw OciException::blobUnknown($digest);
        }

        return response('', 200, [
            'Content-Length' => (string) $blob->size,
            'Docker-Content-Digest' => $blob->digest,
        ]);
    }

    /**
     * Resolves the "from" repository through the same read-path lookup any other
     * repository name goes through (RegistryAccessService::packagesFor(), the same set
     * ResolvesOciRepository::ociRepository() draws from) — no special-cased query for
     * "from" that could answer a different existence question than a normal GET would.
     */
    private function mountFrom(Group $group, Package $target, string $digest, string $from): ?OciBlob
    {
        Digest::assertValid($digest);

        $source = $this->access->packagesFor($group)
            ->first(fn (Package $p): bool => $p->type === PackageType::Docker && $p->name === $from);

        if ($source === null || $source->organization_id !== $target->organization_id) {
            return null;
        }

        return $this->blobs->mount($target, $digest);
    }

    private function findUpload(Package $package, string $uploadId): OciBlobUpload
    {
        $upload = OciBlobUpload::where('id', $uploadId)->where('package_id', $package->id)->first();

        if ($upload === null) {
            // Not part of the OCI error-body protocol: an unknown/expired upload session
            // is not a case any test in this task exercises, and the OCI distribution spec
            // has no error code of its own for it distinct from BLOB_UPLOAD_UNKNOWN, which
            // this codebase's OciException does not yet define. A plain 404 is refused
            // rather than guessed at here.
            throw new NotFoundHttpException;
        }

        return $upload;
    }

    private function sessionResponse(string $name, OciBlobUpload $upload): Response
    {
        return response('', 202, [
            'Location' => "/v2/{$name}/blobs/uploads/{$upload->id}",
            'Range' => '0-'.max($upload->offset - 1, 0),
            'Docker-Upload-UUID' => $upload->id,
        ]);
    }

    private function blobCompletedResponse(string $name, OciBlob $blob): Response
    {
        return response('', 201, [
            'Docker-Content-Digest' => $blob->digest,
            'Location' => "/v2/{$name}/blobs/{$blob->digest}",
        ]);
    }
}

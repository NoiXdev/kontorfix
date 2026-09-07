<?php

namespace App\Http\Controllers\Registry\Oci;

use App\Enums\PackageType;
use App\Exceptions\OciException;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\OciBlob;
use App\Models\OciBlobUpload;
use App\Models\OciManifest;
use App\Models\Package;
use App\Services\Oci\BlobStore;
use App\Services\Oci\Digest;
use App\Services\RegistryAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

        $digest = $this->requireDigest($request);

        // The client MAY attach a final chunk to this request. append() treats an empty
        // body the same as any other chunk (it appends zero bytes), so there is no need to
        // special-case "nothing left to send" here.
        $this->blobs->append($upload, $request->getContent(asResource: true));

        $blob = $this->blobs->finish($upload, $digest);

        return $this->blobCompletedResponse($name, $blob);
    }

    /**
     * GET/HEAD /v2/{name}/blobs/{digest}. HEAD answers with headers only, exactly as it did
     * before this method knew how to serve a body — checked first and returned immediately,
     * so nothing below it can ever change what a HEAD response looks like.
     *
     * GET takes one of two branches, chosen by `BlobStore::isLocalDisk()` — the same
     * adapter-instance check `BlobStore::append()`/`finish()` already use to decide how to
     * write a blob, reused here rather than re-asked against
     * `StorageSetting::current()->driver`, which is a configured *intent* and not proof of
     * what the resolved disk actually is:
     *
     *   - Local: streamed back in chunks (see the StreamedResponse below).
     *   - Remote (S3 today): a 302 to a short-lived presigned URL, so this process never
     *     reads a payload byte at all.
     */
    public function show(Request $request, string $name, string $digest): SymfonyResponse
    {
        $group = $this->ociGroup($request);
        $package = $this->ociRepository($request, $group, $name);

        // Scoped to this package's own organization — never by digest alone — so a token
        // cannot use this endpoint to probe whether some OTHER tenant holds a given layer.
        // NOT sufficient on its own for a SHARED package, though: oci_blobs deduplicates
        // per organization (spec §2), so $package->organization_id for a shared repository
        // is the OPERATOR's organization, and every OTHER Docker repository that operator
        // owns — shared or not — stores its blobs under that same organization_id. A plain
        // read token on ONE shared repository could otherwise pull ANY blob the operator
        // organization has ever stored, including from a repository never shared with
        // anyone: the org boundary is real, but it is not this repository's boundary once
        // more than one repository shares it.
        $blob = $this->blobs->find((string) $package->organization_id, $digest);

        if ($blob === null) {
            throw OciException::blobUnknown($digest);
        }

        // The extra check is scoped to SHARED packages only, deliberately, not applied to
        // every pull: for an ordinary (non-shared) repository, every reader of it is
        // already a member of the SAME organization the blob belongs to — there is no
        // OTHER tenant to disclose it to, so requiring the digest to already be named by
        // one of $package's own manifests would only break two things this endpoint is
        // supposed to support: `HEAD .../blobs/<digest>` checked right after this repo's
        // OWN blob upload, before any manifest references it yet (the ordinary "is this
        // layer already here" shortcut a push makes BEFORE writing its manifest — see
        // BlobUploadTest's "reports an existing blob by digest" and BlobDownloadTest's
        // streaming/redirect/HEAD cases, none of which push a manifest at all), and the
        // cross-repository MOUNT feature (§3), which deliberately makes one organization's
        // blob available to a second repository of that SAME organization without either
        // one's manifest naming it first. Both are legitimate same-tenant sharing; neither
        // is the leak this method exists to close.
        if ($package->shared && ! $this->referencedByPackage($package, $digest)) {
            throw OciException::blobUnknown($digest);
        }

        if ($request->isMethod('HEAD')) {
            return response('', 200, [
                'Content-Length' => (string) $blob->size,
                'Docker-Content-Digest' => $blob->digest,
            ]);
        }

        if (! $this->blobs->isLocalDisk()) {
            return redirect()->away($this->blobs->presignedUrl($blob));
        }

        // Opened BEFORE the StreamedResponse is constructed — and thus before this method
        // commits to a 200 with a Content-Length — precisely so a missing file (an
        // oci_blobs row that outlived its bytes: a database restore older than the
        // artifacts volume, an operator repointing the artifacts root) is still answerable
        // as BLOB_UNKNOWN. Opening the stream lazily inside the response callback would
        // mean the 200 and Content-Length headers have already gone out by the time the
        // failure is discovered, leaving the client with a truncated body and no server-side
        // error to diagnose it — readStream() returning null here is exactly that failure,
        // normalised to one shape regardless of the disk's own `throw` setting.
        $stream = $this->blobs->readStream($blob);

        if ($stream === null) {
            throw OciException::blobUnknown($digest);
        }

        // A pull on local storage occupies a FrankenPHP worker thread for the whole transfer.
        // For a large image over a slow link that is minutes, and docker/compose.yaml already
        // warns about thread-pool saturation at its healthcheck. S3 answers with a redirect
        // instead and never touches a payload byte; that is the difference the storage
        // settings page names at the point where the backend is chosen.
        return new StreamedResponse(function () use ($stream): void {
            while (! feof($stream)) {
                echo fread($stream, 1024 * 1024);
                // @ob_flush() before flush(), matching Symfony's own StreamedResponse::
                // sendContent() — harmless today (docker/php.ini ships with this project
                // now, but it does not set output_buffering, so PHP's own compiled default
                // of Off still applies — confirmed via `php -i`, not assumed — and there is
                // no userland buffer to flush), but without it, an ini that turns output
                // buffering on would accumulate the whole blob in that buffer regardless of
                // this chunk loop, which is exactly the memory blowup streaming exists to
                // avoid.
                @ob_flush();
                flush();
            }

            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $blob->size,
            'Docker-Content-Digest' => $blob->digest,
        ]);
    }

    /**
     * Resolves the "from" repository through the same read-path lookup any other
     * repository name goes through (RegistryAccessService::packagesFor(), the same set
     * ResolvesOciRepository::ociRepository() draws from) — no special-cased query for
     * "from" that could answer a different existence question than a normal GET would.
     *
     * The organization comparison below carries exactly one of the two guarantees this
     * method might look like it makes, and it matters which:
     *
     *   - It does NOT stop this method from ever returning ANOTHER organization's blob.
     *     That guarantee is carried unconditionally by BlobStore::mount()/find(), which
     *     scope by $target's own organization_id and never by digest alone — no source
     *     package, present or absent, foreign or not, can make find() hand back a row it
     *     does not own. That half holds whether or not this comparison exists.
     *   - It DOES decide what happens when $target's OWN organization independently
     *     already holds the announced digest — a shared base layer is the ordinary case
     *     for this, not an edge one. Without the comparison, mount() would find and return
     *     $target's own pre-existing blob (organization-scoped lookup, so it is a real hit,
     *     just not one that has anything to do with "from"), and this endpoint would
     *     answer 201 instead of 202 for a source name that does not actually hold anything
     *     for THIS organization. Verified directly: deleting this line turns
     *     tests/Feature/Oci/BlobUploadTest.php's "refuses a foreign mount source even when
     *     the target already holds the same digest itself" from 202 to 201; the sibling
     *     "falls back ... mount source is in another organization" test, which has no such
     *     coincidental digest, stays green either way and does not exercise this line.
     *
     * In short: this comparison is load-bearing for OBSERVABLE BEHAVIOUR (which status
     * code a same-digest-different-source request gets), not for TENANCY (no foreign
     * organization's content can be disclosed either way). Kept because "confirms the
     * target's own blob under an unrelated source's name" is a confusing accidental
     * success this method should refuse outright rather than let happen to work.
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

    /**
     * Whether $digest is a blob $package's OWN manifests actually name — its own boundary
     * of "belongs to this repository", since oci_blobs itself carries none (blobs
     * deduplicate per ORGANIZATION, never per repository — spec §2). Without this, show()
     * would serve any blob the owning organization has ever stored to a holder of any ONE
     * of that organization's repositories, shared or not; see show()'s own comment for the
     * full account.
     *
     * Walks every manifest of $package, including ones no tag currently names (an index's
     * per-platform children are pushed by digest alone — see the `manifests` case in
     * childManifestDigests()) — a real `docker pull` always resolves the manifest for THIS
     * repository first and only then asks for the blobs it names, so nothing legitimate is
     * ever excluded by scoping to $package rather than to what happens to be tagged.
     */
    private function referencedByPackage(Package $package, string $digest): bool
    {
        $manifests = OciManifest::where('package_id', $package->id)->get(['id', 'payload']);

        foreach ($manifests as $manifest) {
            if (in_array($digest, $this->referencedDigests($manifest), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The digests one manifest names directly: its config blob and every layer for an
     * image manifest, or the per-platform child manifest digests for an index — those
     * children are OciManifest rows of their own (see referencedByPackage()'s own
     * comment), never blobs directly, so they are matched here too rather than only
     * layers/config, exactly as `docker pull` itself would walk an index before ever
     * asking for a blob.
     *
     * @return list<string>
     */
    private function referencedDigests(OciManifest $manifest): array
    {
        $payload = json_decode($manifest->payload, true);
        if (! is_array($payload)) {
            return [];
        }

        $digests = [];

        $configDigest = $payload['config']['digest'] ?? null;
        if (is_string($configDigest)) {
            $digests[] = $configDigest;
        }

        foreach (['layers', 'manifests'] as $key) {
            foreach ($payload[$key] ?? [] as $entry) {
                $entryDigest = is_array($entry) ? ($entry['digest'] ?? null) : null;
                if (is_string($entryDigest)) {
                    $digests[] = $entryDigest;
                }
            }
        }

        return $digests;
    }

    /**
     * `digest` as a plain query string value. `PUT .../uploads/{id}?digest[]=x` sends it as
     * an array instead — `(string) $array` is an uncaught `Array to string conversion`
     * TypeError/warning that would escape the OCI JSON error contract as a bare 500.
     * Mirrors the `is_string()` guard begin() already applies to the same query parameter.
     */
    private function requireDigest(Request $request): string
    {
        $digest = $request->query('digest');

        if (! is_string($digest)) {
            throw OciException::unsupported('Nur sha256-Digests werden unterstützt.');
        }

        Digest::assertValid($digest);

        return $digest;
    }

    private function findUpload(Package $package, string $uploadId): OciBlobUpload
    {
        $upload = OciBlobUpload::where('id', $uploadId)->where('package_id', $package->id)->first();

        if ($upload === null) {
            // Not part of the OCI error-body protocol: an unknown/expired upload session
            // is not a case any test in this task exercises, and the OCI distribution spec
            // has no error code of its own for it distinct from BLOB_UPLOAD_UNKNOWN, which
            // this codebase's OciException does not yet define. A plain 404 (Laravel's own
            // shape, not an errors[] envelope) is thrown deliberately here rather than
            // guessing at a JSON body the spec does not define.
            throw new NotFoundHttpException;
        }

        return $upload;
    }

    private function sessionResponse(string $name, OciBlobUpload $upload): Response
    {
        $headers = [
            'Location' => "/v2/{$name}/blobs/uploads/{$upload->id}",
            'Docker-Upload-UUID' => $upload->id,
        ];

        // Omitted rather than "0-0" when nothing has been received yet: "0-0" claims one
        // byte is already on the server, and a client resuming after an interrupted POST
        // would trust that and skip the first byte of its retry.
        if ($upload->offset > 0) {
            $headers['Range'] = '0-'.($upload->offset - 1);
        }

        return response('', 202, $headers);
    }

    private function blobCompletedResponse(string $name, OciBlob $blob): Response
    {
        return response('', 201, [
            'Docker-Content-Digest' => $blob->digest,
            'Location' => "/v2/{$name}/blobs/{$blob->digest}",
        ]);
    }
}

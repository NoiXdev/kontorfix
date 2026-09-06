<?php

namespace App\Http\Controllers\Registry\Oci;

use App\Exceptions\OciException;
use App\Http\Controllers\Controller;
use App\Models\OciTag;
use App\Services\Oci\ManifestStore;
use App\Services\RegistryAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Manifests and tags: the part that turns the layers Task 4's blob protocol uploaded into a
 * pullable image.
 *
 * Deliberately does not validate what it is handed. It does not parse a manifest for its
 * `schemaVersion`, does not check that the layers it references exist as blobs, and does not
 * reject an unfamiliar `mediaType` — an image index (`application/vnd.oci.image.index.v1+json`
 * or the Docker manifest-list equivalent) goes through the exact same put()/show() path as a
 * single image manifest. The payload is opaque, digest-addressed bytes; a registry that
 * second-guesses its contents breaks artifact types it has never heard of, cosign signatures
 * among them.
 */
class ManifestController extends Controller
{
    use ResolvesOciRepository;

    public function __construct(
        private readonly RegistryAccessService $access,
        private readonly ManifestStore $manifests,
    ) {}

    protected function access(): RegistryAccessService
    {
        return $this->access;
    }

    /**
     * GET/HEAD /v2/{name}/manifests/{reference} — $reference is a tag or a digest, resolved
     * identically either way. The response carries Content-Type and Docker-Content-Digest
     * from the STORED row, not from anything on the request: a client compares
     * Docker-Content-Digest against what it asked for to confirm it got the right manifest.
     */
    public function show(Request $request, string $name, string $reference): Response
    {
        $group = $this->ociGroup($request);
        $package = $this->ociRepository($request, $group, $name);

        $manifest = $this->manifests->find($package, $reference);

        if ($manifest === null) {
            throw OciException::manifestUnknown($reference);
        }

        return response($manifest->payload, 200, [
            'Content-Type' => $manifest->media_type,
            'Content-Length' => (string) $manifest->size,
            'Docker-Content-Digest' => $manifest->digest,
        ]);
    }

    /**
     * PUT /v2/{name}/manifests/{reference} — the raw request body is read as a string, never
     * decoded, and handed to ManifestStore::put() untouched: those exact bytes are what the
     * response digest and every later GET must reproduce byte for byte.
     */
    public function put(Request $request, string $name, string $reference): Response
    {
        $group = $this->ociGroup($request);
        $package = $this->ociWritableRepository($request, $group, $name);

        $mediaType = $request->header('Content-Type') ?? 'application/vnd.oci.image.manifest.v1+json';

        $manifest = $this->manifests->put($package, $reference, $request->getContent(), $mediaType);

        return response('', 201, [
            'Docker-Content-Digest' => $manifest->digest,
            'Location' => "/v2/{$name}/manifests/{$manifest->digest}",
        ]);
    }

    /**
     * DELETE /v2/{name}/manifests/{digest} — digest only, per the route constraint below;
     * the OCI spec does not allow deleting a manifest by tag. Deleting the OciManifest row
     * cascades to every OciTag pointing at it via the database's own composite foreign key
     * (see the oci_tags migration) — nothing here has to walk and delete tags by hand.
     */
    public function destroy(Request $request, string $name, string $digest): Response
    {
        $group = $this->ociGroup($request);
        $package = $this->ociWritableRepository($request, $group, $name);

        $manifest = $this->manifests->find($package, $digest);

        if ($manifest === null) {
            throw OciException::manifestUnknown($digest);
        }

        $manifest->delete();

        return response('', 202);
    }

    /** GET /v2/{name}/tags/list */
    public function tags(Request $request, string $name): JsonResponse
    {
        $group = $this->ociGroup($request);
        $package = $this->ociRepository($request, $group, $name);

        $names = OciTag::where('package_id', $package->id)->orderBy('name')->pluck('name');

        return response()->json([
            'name' => $name,
            'tags' => $names,
        ]);
    }
}

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

    /** See readManifestBody() for why this exists and why it is 4 MiB. */
    private const MAX_MANIFEST_BYTES = 4 * 1024 * 1024;

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

        // `oci_manifests.media_type` is a plain `varchar(255)` (see the create-tables
        // migration) — an unbounded client-supplied Content-Type reaching it as a bound
        // parameter raises Postgres' "value too long for type character varying(255)" as
        // an uncaught QueryException: a 500 with a stack trace instead of an OCI error
        // body, for a header no real OCI client would ever send this long (the spec's own
        // media types top out well under 100 characters). Refused here, before the write,
        // with the same error code Digest::assertValid() uses for a value this endpoint
        // does not recognise as valid input.
        if (strlen($mediaType) > 255) {
            throw OciException::unsupported('Der Content-Type ist zu lang.');
        }

        $manifest = $this->manifests->put($package, $reference, $this->readManifestBody($request), $mediaType);

        // Addressed name, not the bare one: on a path-namespaced address a Location built
        // from `{name}` alone points at a URL that names no registry. See
        // ResolvesOciRepository::ociAddressedName().
        $addressed = $this->ociAddressedName($request, $name);

        return response('', 201, [
            'Docker-Content-Digest' => $manifest->digest,
            'Location' => "/v2/{$addressed}/manifests/{$manifest->digest}",
        ]);
    }

    /**
     * The manifest body, with the ONLY ceiling that stands between a `PUT .../manifests/`
     * and `memory_limit`.
     *
     * Nothing else bounds it. `/v2/*` is deliberately exempt from Laravel's own
     * post-size guard (App\Http\Middleware\ValidatePostSize — a blob legitimately exceeds
     * `post_max_size` by design), `post_max_size` itself never gates a body application
     * code reads for itself, and docker/Caddyfile's `@oversized` rule exempts `/v2/*`
     * outright. The blob endpoints need exactly that freedom and are safe with it because
     * they STREAM: `BlobStore::append()` takes a resource and never holds the payload. This
     * one does not — a manifest has to be hashed and stored whole — so a plain
     * `$request->getContent()` here was an unbounded read into a single PHP string,
     * reachable by any publish token, bounded by nothing but the process's memory.
     *
     * 4 MiB is the same ceiling Docker's own registry applies, and it is three orders of
     * magnitude above what a real manifest costs: an image manifest naming a hundred layers
     * is a few kilobytes, and an index naming a dozen platforms is smaller still.
     *
     * Read as a STREAM with an explicit cap rather than checked against `Content-Length`:
     * a chunked body carries no `Content-Length` at all (see docker/php.ini on why nothing
     * upstream can see one either), so a header check alone would leave the one shape that
     * cannot be refused earlier as the one shape that is not refused here. The declared
     * length is still checked first, purely so an oversized declared body is refused before
     * a single byte of it is read.
     */
    private function readManifestBody(Request $request): string
    {
        $declared = $request->headers->get('Content-Length');

        if ($declared !== null && ctype_digit($declared) && (int) $declared > self::MAX_MANIFEST_BYTES) {
            throw OciException::manifestTooLarge(self::MAX_MANIFEST_BYTES);
        }

        $stream = $request->getContent(asResource: true);

        // One byte past the ceiling, so "exactly at the limit" and "over it" stay
        // distinguishable — reading only MAX_MANIFEST_BYTES would silently truncate an
        // oversized body into an accepted one whose digest then disagrees with the client's.
        $payload = is_resource($stream)
            ? (string) stream_get_contents($stream, self::MAX_MANIFEST_BYTES + 1)
            : '';

        if (strlen($payload) > self::MAX_MANIFEST_BYTES) {
            throw OciException::manifestTooLarge(self::MAX_MANIFEST_BYTES);
        }

        return $payload;
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

    /**
     * GET /v2/{name}/tags/list — the one responder that hands a repository NAME back in a
     * body rather than in a header, and therefore the one that has to state it in the
     * CALLER's address space just as every `Location` does (ResolvesOciRepository::
     * ociAddressedName(), which exists for exactly this reason).
     *
     * `{name}` arrives bare — that is what every lookup is against — so answering with it
     * verbatim told a client that asked `/v2/3b/intern/meinapp/tags/list` that the
     * repository is called `meinapp`, which on the instance host names nothing: it is fewer
     * than three segments and a plain 404. `docker` never calls this endpoint, so bin/e2e
     * stayed green through it; `crane ls` and `skopeo list-tags` do.
     */
    public function tags(Request $request, string $name): JsonResponse
    {
        $group = $this->ociGroup($request);
        $package = $this->ociRepository($request, $group, $name);

        $names = OciTag::where('package_id', $package->id)->orderBy('name')->pluck('name');

        return response()->json([
            'name' => $this->ociAddressedName($request, $name),
            'tags' => $names,
        ]);
    }
}

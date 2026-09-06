<?php

namespace App\Services\Oci;

use App\Exceptions\OciException;
use App\Models\OciBlob;
use App\Models\OciBlobUpload;
use App\Models\Package;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The only class that touches the artifacts disk for OCI blobs. Both the plain upload
 * protocol and cross-repository mount go through here, so the two invariants that matter —
 * organization scoping and "verify before promote" — live in exactly one place rather than
 * being restated at every call site.
 */
final class BlobStore
{
    private function disk(): Filesystem
    {
        // Never storage_path(): the artifacts disk is reconfigured at runtime from the
        // operator's storage settings (see App\Services\Storage\StorageManager) and may be
        // a local path or S3 — going through the disk is what keeps this class correct
        // under either.
        return Storage::disk('artifacts');
    }

    /** Opens a new upload session for a repository, with an empty partial file behind it. */
    public function begin(Package $package): OciBlobUpload
    {
        $upload = OciBlobUpload::create([
            'package_id' => $package->id,
            'path' => 'docker/uploads/'.(string) Str::uuid(),
            'offset' => 0,
            // Generous but bounded: an abandoned session (client crash, dropped connection)
            // leaves a partial file behind rather than growing the disk forever. Nothing in
            // this task sweeps expired rows yet — that is future work, not a claim this
            // class makes.
            'expires_at' => now()->addDay(),
        ]);

        $this->disk()->put($upload->path, '');

        return $upload;
    }

    /**
     * Appends a chunk onto the upload's partial file and returns the new total offset.
     *
     * $stream MUST be the resource form of the request body
     * (`$request->getContent(asResource: true)`), never the string form — reading a 500 MiB
     * layer as a string would hold it in memory twice (once as the framework's buffer, once
     * again as this method's own copy).
     *
     * Flysystem — and S3 in particular — has no "append to an existing object" operation,
     * so a growing upload cannot be streamed onto the destination in place. Instead the
     * existing bytes and the new chunk are copied, in order, into one temporary local
     * stream, which is then written back to the disk as the new partial file.
     * stream_copy_to_stream is used on both sides of that copy so neither the existing
     * content nor the incoming chunk is ever materialised as a PHP string.
     */
    public function append(OciBlobUpload $upload, mixed $stream): int
    {
        $combined = fopen('php://temp', 'w+b');

        $existing = $this->disk()->readStream($upload->path);
        stream_copy_to_stream($existing, $combined);
        fclose($existing);

        $written = stream_copy_to_stream($stream, $combined);

        rewind($combined);
        $this->disk()->writeStream($upload->path, $combined);
        fclose($combined);

        $offset = $upload->offset + $written;
        $upload->update(['offset' => $offset]);

        return $offset;
    }

    /**
     * Verifies the completed upload against the digest the client announced, then promotes
     * it into content-addressed storage. The digest is checked BEFORE the blob is promoted,
     * never after: in a content-addressable store a mismatched blob is not a failed upload,
     * it is a poisoned entry every later reference by that digest would resolve to.
     */
    public function finish(OciBlobUpload $upload, string $expectedDigest): OciBlob
    {
        Digest::assertValid($expectedDigest);

        // Re-reads the completed file to hash it, rather than carrying incremental hash
        // state across the PATCH/PUT requests that built it up: PHP's hashing context
        // cannot be serialised between requests, so the only alternatives are this one
        // extra sequential read, or trusting the client's own claim about the content —
        // and trusting the client is exactly what a content-addressable store cannot do.
        $actualDigest = $this->hashFile($upload->path);

        if ($actualDigest !== $expectedDigest) {
            // Delete the partial file BEFORE throwing, and write no OciBlob row: nothing
            // that failed verification may ever reach content-addressed storage. The
            // upload session itself is also removed — its file is gone, so nothing could
            // resume it — leaving the client to restart with a fresh POST.
            $this->disk()->delete($upload->path);
            $upload->delete();

            throw OciException::digestInvalid($expectedDigest, $actualDigest);
        }

        $package = $upload->package;
        $organizationId = (string) $package->organization_id;
        $path = Digest::pathFor($organizationId, $expectedDigest);
        $size = $this->disk()->size($upload->path);

        if ($this->disk()->exists($path)) {
            // Another push already promoted the identical bytes under this digest.
            // Content-addressing means there is nothing new to store — only the now-
            // redundant partial file to discard.
            $this->disk()->delete($upload->path);
        } else {
            $this->disk()->move($upload->path, $path);
        }

        // firstOrCreate, not create: two concurrent pushes of the same base layer (the
        // normal case, not an edge case — e.g. two images built FROM the same base) both
        // pass verification and both reach here. A plain create() would have the second
        // one fail on the (organization_id, digest) unique index for what is, from the
        // caller's point of view, a successful push.
        $blob = OciBlob::firstOrCreate(
            ['organization_id' => $organizationId, 'digest' => $expectedDigest],
            ['size' => $size, 'path' => $path],
        );

        $upload->delete();

        return $blob;
    }

    /**
     * Looks up a blob by digest, scoped to one organization. Never scoped by digest alone —
     * that is exactly what would let a token probe whether some OTHER tenant holds a given
     * layer, since a blob's presence would then depend on what other customers pushed.
     */
    public function find(string $organizationId, string $digest): ?OciBlob
    {
        return OciBlob::where('organization_id', $organizationId)->where('digest', $digest)->first();
    }

    /**
     * Cross-repository mount: attaches an existing blob to a different repository WITHOUT
     * copying any bytes, since content-addressed storage is already shared across every
     * repository of one organization — the blob's row is organization-scoped, never
     * package-scoped, so "mounting" it is nothing more than confirming it exists here.
     *
     * Scoped by the TARGET's organization, never by digest alone, for the same reason
     * find() is: the caller supplies a "from" repository name, and a hit/miss that
     * depended on some other tenant's blob would turn this into exactly the cross-tenant
     * oracle organization-scoping exists to prevent.
     */
    public function mount(Package $target, string $digest): ?OciBlob
    {
        return $this->find((string) $target->organization_id, $digest);
    }

    private function hashFile(string $path): string
    {
        $stream = $this->disk()->readStream($path);
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return 'sha256:'.hash_final($context);
    }
}

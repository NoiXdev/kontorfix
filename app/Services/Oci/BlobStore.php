<?php

namespace App\Services\Oci;

use App\Exceptions\OciException;
use App\Models\OciBlob;
use App\Models\OciBlobUpload;
use App\Models\Package;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use RuntimeException;

/**
 * The only class that touches the artifacts disk for OCI blobs. Both the plain upload
 * protocol and cross-repository mount go through here, so the two invariants that matter —
 * organization scoping and "verify before promote" — live in exactly one place rather than
 * being restated at every call site.
 *
 * append() takes one of two paths depending on what the artifacts disk actually resolves
 * to — checked by inspecting the resolved Flysystem adapter's class (isLocalDisk() below),
 * never assumed from the configured driver name:
 *
 *   - A genuinely local disk opens the partial file directly with `fopen(..., 'ab')` and
 *     appends the incoming chunk in place, using the OS's own O_APPEND semantics. One
 *     write, no intermediate buffer, no read of what is already on disk.
 *   - Any other disk (S3 today) cannot append to an existing object at all, so each chunk
 *     is written as its OWN object instead of a read-modify-write of the accumulated file.
 *     finish() reassembles the parts, in offset order, in one single streaming pass.
 *
 * An earlier version of append() read the ENTIRE partial file back on every call just to
 * write it straight back out again alongside the new chunk — quadratic total I/O in the
 * number of chunks (a 500 MiB layer in 50 chunks moved on the order of 25 GiB), and a
 * `php://temp` buffer sized to the whole accumulated upload on every single call. Neither
 * code path below reads back anything already on disk during append() — only the incoming
 * chunk is ever touched per call. finish() is the one place that pays for the full size,
 * and it pays for it exactly once, which is unavoidable: the digest has to be checked
 * before promotion, and PHP cannot carry a hash context across requests (see finish()).
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

    /**
     * Whether the artifacts disk can be appended to in place with a plain `fopen()`.
     * Answered by inspecting the actual resolved Flysystem adapter instance, not by
     * reading the configured driver name: "local" is merely what StorageManager falls back
     * to when no S3 setting is stored, and a driver *name* is not proof that `disk()->path()`
     * returns a path this process can open — the adapter class is Flysystem's own answer to
     * exactly that question, so it is the one thing this method trusts.
     *
     * Public because BlobController::show() asks the identical question when deciding
     * whether to stream a pull's bytes itself or redirect it to a presigned URL: the same
     * "is this actually a local filesystem, not merely configured to say so" concern
     * applies to reading a blob back out as much as it does to writing one in, so the
     * download path reuses this rather than growing a second, weaker check against
     * `StorageSetting::current()->driver`.
     */
    public function isLocalDisk(): bool
    {
        return $this->disk()->getAdapter() instanceof LocalFilesystemAdapter;
    }

    /**
     * Opens a readable stream over an already-promoted blob's bytes, for
     * BlobController::show() to copy to the client in chunks on the local branch without
     * ever loading the whole file into memory. The caller owns the returned resource and
     * must fclose() it.
     *
     * Returns null rather than letting a missing file surface as whatever the underlying
     * disk throws or returns: an `oci_blobs` row can outlive its file (a database restore
     * older than the artifacts volume, an operator repointing the artifacts root), and the
     * caller MUST check for null and answer BLOB_UNKNOWN *before* committing to a 200 —
     * see the call site in BlobController::show(), which opens this before constructing
     * the StreamedResponse for exactly that reason.
     *
     * @return resource|null
     */
    public function readStream(OciBlob $blob)
    {
        try {
            return $this->disk()->readStream($blob->path);
        } catch (UnableToReadFile) {
            // Thrown rather than returned as null when the disk config has `throw => true`
            // (see StorageManager::diskConfigFor()) — normalised to null here so the
            // caller has exactly one shape to check, regardless of that setting.
            return null;
        }
    }

    /**
     * A short-lived presigned URL for an already-promoted blob, for BlobController::show()
     * to redirect a pull to on the remote branch — the point of which is that PHP never
     * reads a payload byte at all.
     */
    public function presignedUrl(OciBlob $blob): string
    {
        return $this->disk()->temporaryUrl($blob->path, now()->addMinutes(5));
    }

    /** Opens a new upload session for a repository. */
    public function begin(Package $package): OciBlobUpload
    {
        $upload = OciBlobUpload::create([
            'package_id' => $package->id,
            'path' => 'docker/uploads/'.(string) Str::uuid(),
            'offset' => 0,
            // Generous but bounded: an abandoned session (client crash, dropped connection)
            // leaves a partial file (or a directory of parts) behind rather than growing
            // the disk forever. Nothing in this task sweeps expired rows — recorded as a
            // Plan B requirement, not built here.
            'expires_at' => now()->addDay(),
        ]);

        if ($this->isLocalDisk()) {
            // Not required for correctness — appendLocal() below opens with 'ab', which
            // creates the file if it is missing — but it means finish() always has
            // something to hash even if it is ever reached with zero appends, rather than
            // failing on a missing file. The remote path needs no equivalent: an empty
            // parts listing hashes to the empty string cleanly (see hashRemoteParts()).
            $this->disk()->put($upload->path, '');
        }

        return $upload;
    }

    /**
     * Appends a chunk and returns the new total offset. $stream MUST be the resource form
     * of the request body (`$request->getContent(asResource: true)`), never the string
     * form — reading a 500 MiB layer as a string would hold it in memory twice (once as the
     * framework's own buffer, once again as this method's copy).
     */
    public function append(OciBlobUpload $upload, mixed $stream): int
    {
        $written = $this->isLocalDisk()
            ? $this->appendLocal($upload, $stream)
            : $this->appendRemotePart($upload, $stream);

        $offset = $upload->offset + $written;
        $upload->update(['offset' => $offset]);

        return $offset;
    }

    /**
     * Appends directly onto the partial file with the OS's own O_APPEND semantics — no read
     * of the existing content, no intermediate buffer, one write of exactly the incoming
     * chunk. This bypasses Flysystem entirely (its local adapter has no append operation of
     * its own); safe only because isLocalDisk() has already confirmed the disk really is a
     * local filesystem this process can open by path.
     */
    private function appendLocal(OciBlobUpload $upload, mixed $stream): int
    {
        $absolutePath = $this->disk()->path($upload->path);

        // Bypassing Flysystem also means bypassing the directory creation Flysystem's own
        // write methods normally do — fopen() does not create parent directories.
        $directory = dirname($absolutePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $handle = fopen($absolutePath, 'ab');
        if ($handle === false) {
            throw new RuntimeException("Could not open upload file for append: {$absolutePath}");
        }

        $written = stream_copy_to_stream($stream, $handle);
        fclose($handle);

        return $written === false ? 0 : $written;
    }

    /**
     * No native append on a remote object store, so each chunk becomes its own object
     * instead of a read-modify-write of the accumulated file — every PATCH moves exactly
     * its own bytes. Parts are named by the offset they start at, which is already tracked
     * on $upload (no extra column needed); finish() uses that name purely to put them back
     * in order.
     */
    private function appendRemotePart(OciBlobUpload $upload, mixed $stream): int
    {
        $partPath = $upload->path.'/'.$upload->offset;
        $this->disk()->writeStream($partPath, $stream);

        return $this->disk()->size($partPath);
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

        // Re-reads the completed upload to hash it, rather than carrying incremental hash
        // state across the PATCH/PUT requests that built it up: PHP's hashing context
        // cannot be serialised between requests, so the only alternatives are this one
        // extra sequential read, or trusting the client's own claim about the content —
        // and trusting the client is exactly what a content-addressable store cannot do.
        // This re-read is unavoidable on the chunked path for that reason; it is NOT
        // avoided on the monolithic path either (begin()+append()+finish() in one request)
        // even though a single request could in principle carry a live hash context from
        // append() through to finish() — that shortcut is deliberately not taken here, so
        // both paths through this method behave identically rather than one being "the
        // fast one nobody remembers to keep correct".
        $actualDigest = $this->isLocalDisk()
            ? $this->hashLocalFile($upload->path)
            : $this->hashRemoteParts($upload);

        if ($actualDigest !== $expectedDigest) {
            // Delete the partial upload BEFORE throwing, and write no OciBlob row: nothing
            // that failed verification may ever reach content-addressed storage. The
            // upload session itself is also removed — its storage is gone, so nothing could
            // resume it — leaving the client to restart with a fresh POST.
            $this->deleteUploadStorage($upload);
            $upload->delete();

            throw OciException::digestInvalid($expectedDigest, $actualDigest);
        }

        $package = $upload->package;
        $organizationId = (string) $package->organization_id;
        $path = Digest::pathFor($organizationId, $expectedDigest);

        if ($this->disk()->exists($path)) {
            // Another push already promoted the identical bytes under this digest.
            // Content-addressing means there is nothing new to store — only the now-
            // redundant partial upload to discard.
            $this->deleteUploadStorage($upload);
        } elseif ($this->isLocalDisk()) {
            $this->disk()->move($upload->path, $path);
        } else {
            $this->promoteRemoteParts($upload, $path);
        }

        // $upload->offset is the definitive byte count: every append() call added exactly
        // the bytes it wrote to it, and the hash just verified above was computed over
        // precisely those bytes — so it is used as the blob's size rather than asking the
        // disk to measure the file again.
        //
        // firstOrCreate, not create: two concurrent pushes of the same base layer (the
        // normal case, not an edge case — e.g. two images built FROM the same base) both
        // pass verification and both reach here. A plain create() would have the second
        // one fail on the (organization_id, digest) unique index for what is, from the
        // caller's point of view, a successful push.
        $blob = OciBlob::firstOrCreate(
            ['organization_id' => $organizationId, 'digest' => $expectedDigest],
            ['size' => $upload->offset, 'path' => $path],
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

    private function hashLocalFile(string $path): string
    {
        $stream = $this->disk()->readStream($path);
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return 'sha256:'.hash_final($context);
    }

    /**
     * Hashes the parts in offset order, in one streaming pass, without ever concatenating
     * them in memory or on disk first. An upload with zero parts (finish() reached with no
     * append() call at all) hashes cleanly to the empty string's digest rather than
     * failing on a missing file, unlike the local path's hashLocalFile().
     */
    private function hashRemoteParts(OciBlobUpload $upload): string
    {
        $context = hash_init('sha256');

        foreach ($this->sortedPartPaths($upload) as $partPath) {
            $stream = $this->disk()->readStream($partPath);
            hash_update_stream($context, $stream);
            fclose($stream);
        }

        return 'sha256:'.hash_final($context);
    }

    /**
     * Assembles the parts into the final destination in one pass — NOT the one point in
     * this class where the full content is streamed as a whole; hashRemoteParts() just
     * above does the identical full-content streaming pass immediately before this one
     * runs, to compute the digest finish() verifies before ever calling this method. What
     * IS true of both: each happens exactly once per upload, at promotion, never once per
     * chunk — the two full passes are unavoidable (the digest must be checked before
     * promotion, and PHP cannot carry a hash context across the parts finish() did not
     * write itself), not a sign either one is doing more work than it needs to.
     */
    private function promoteRemoteParts(OciBlobUpload $upload, string $destination): void
    {
        $combined = fopen('php://temp', 'w+b');

        foreach ($this->sortedPartPaths($upload) as $partPath) {
            $stream = $this->disk()->readStream($partPath);
            stream_copy_to_stream($stream, $combined);
            fclose($stream);
        }

        rewind($combined);
        $this->disk()->writeStream($destination, $combined);
        fclose($combined);

        $this->disk()->deleteDirectory($upload->path);
    }

    private function deleteUploadStorage(OciBlobUpload $upload): void
    {
        if ($this->isLocalDisk()) {
            $this->disk()->delete($upload->path);
        } else {
            $this->disk()->deleteDirectory($upload->path);
        }
    }

    /** @return list<string> */
    private function sortedPartPaths(OciBlobUpload $upload): array
    {
        $parts = $this->disk()->files($upload->path);
        usort($parts, fn (string $a, string $b): int => (int) basename($a) <=> (int) basename($b));

        return $parts;
    }
}

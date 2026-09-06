<?php

namespace App\Services\Oci;

use App\Exceptions\OciException;
use App\Models\OciManifest;
use App\Models\OciTag;
use App\Models\Package;
use Illuminate\Support\Facades\DB;

/**
 * Manifests and the tags that point at them. The other half of the content-addressed store
 * BlobStore keeps for layers: a manifest is just one more digest-addressed blob, except its
 * bytes are small enough to sit in a `text` column, and a tag is a mutable name on top of
 * that address.
 *
 * The payload is opaque here, deliberately. It is stored and returned byte for byte — never
 * decoded, re-encoded, or inspected for schemaVersion/mediaType/layers — because the digest
 * a client pushes with (and every later reference to it, cosign signatures included) is the
 * hash of those exact bytes. Re-serialising parsed JSON, even losslessly, produces different
 * bytes and therefore a different hash.
 */
final class ManifestStore
{
    /**
     * Computes the digest of the raw payload, upserts the manifest row on
     * `(package_id, digest)`, and — when $reference is a tag rather than a digest — upserts
     * the tag on `(package_id, name)` to point at it.
     *
     * The whole thing runs in one transaction: a manifest row written without its tag would
     * be an image that exists in storage but that no `docker pull <tag>` can ever reach —
     * worse than a failed push, because the client believes the push succeeded.
     *
     * When $reference is itself a digest, it MUST match the hash of $payload — exactly the
     * check BlobStore::finish() already makes for layers, for the same reason: `buildx
     * --push` writes every child manifest of a multi-arch image by digest, so anything that
     * alters the bytes in transit (a proxy, a retry that re-serialises the body) would
     * otherwise store the manifest under its REAL digest while reporting success for the
     * digest the client announced — an address the client believes it just wrote to, that
     * a later GET can never find.
     */
    public function put(Package $package, string $reference, string $payload, string $mediaType): OciManifest
    {
        $digest = Digest::of($payload);

        if ($this->isDigest($reference) && $reference !== $digest) {
            throw OciException::digestInvalid($reference, $digest);
        }

        return DB::transaction(function () use ($package, $reference, $mediaType, $payload, $digest): OciManifest {
            $manifest = OciManifest::updateOrCreate(
                ['package_id' => $package->id, 'digest' => $digest],
                ['media_type' => $mediaType, 'payload' => $payload, 'size' => strlen($payload)],
            );

            if (! $this->isDigest($reference)) {
                OciTag::updateOrCreate(
                    ['package_id' => $package->id, 'name' => $reference],
                    ['manifest_id' => $manifest->id],
                );
            }

            return $manifest;
        });
    }

    /**
     * Resolves a reference — a tag name or a `sha256:…` digest — to the manifest it names,
     * scoped to one repository. A digest is only unique WITHIN a repository, so this never
     * looks manifests up by digest alone.
     */
    public function find(Package $package, string $reference): ?OciManifest
    {
        if ($this->isDigest($reference)) {
            return OciManifest::where('package_id', $package->id)->where('digest', $reference)->first();
        }

        $tag = OciTag::where('package_id', $package->id)->where('name', $reference)->first();

        return $tag?->manifest;
    }

    /**
     * The tag grammar (`$ociReference` in routes/registry.php) never contains a colon, so a
     * leading `sha256:` unambiguously means "this is a digest, not a tag name" — no need to
     * fully re-validate the hex suffix here, the route pattern already did.
     */
    private function isDigest(string $reference): bool
    {
        return str_starts_with($reference, 'sha256:');
    }
}

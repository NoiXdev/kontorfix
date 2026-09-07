<?php

use App\Exceptions\OciException;
use App\Services\Oci\Digest;

it('accepts a well-formed sha256 digest', function () {
    Digest::assertValid('sha256:'.str_repeat('a', 64));
})->throwsNoExceptions();

it('computes the real sha256 digest of the given bytes', function () {
    // Digest::of() itself was never asserted against an independently-known hash anywhere
    // in this suite — every manifest-digest test computes its expected value by calling
    // Digest::of() a second time and comparing that to what the app returns, which is
    // tautological: a consistently WRONG implementation (an off-by-one in the hex
    // encoding, hashing the wrong algorithm, a stray trailing byte) would pass every one
    // of them, because both sides of the assertion share the same bug. It would only ever
    // surface, by accident, through BlobStore's own independent `hash_init('sha256')`
    // verification path disagreeing with it. These values are computed independently of
    // this codebase (`printf '<input>' | shasum -a 256`), so a real implementation bug
    // has somewhere to be caught directly.
    expect(Digest::of(''))->toBe('sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')
        ->and(Digest::of('hello'))->toBe('sha256:2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824')
        ->and(Digest::of('kontorfix-oci-digest-test'))->toBe('sha256:ac46b4ffb1fe5690a73fbb63fced4544644f0fb26226fe1c88bfff1999b73634');
});

it('refuses every other algorithm, because we cannot verify what we cannot compute', function (string $digest) {
    expect(fn () => Digest::assertValid($digest))->toThrow(OciException::class);
})->with([
    'sha512:'.str_repeat('a', 128),
    'md5:'.str_repeat('a', 32),
    'sha256:'.str_repeat('A', 64),   // uppercase hex is not the canonical form
    'sha256:'.str_repeat('a', 63),   // one short
    'sha256',
    '',
]);

it('shards the storage path by the first two hex characters', function () {
    $digest = 'sha256:ab'.str_repeat('c', 62);

    expect(Digest::pathFor('org-1', $digest))->toBe('docker/org-1/blobs/sha256/ab/ab'.str_repeat('c', 62));
});

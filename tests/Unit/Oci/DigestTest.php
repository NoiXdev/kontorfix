<?php

use App\Exceptions\OciException;
use App\Services\Oci\Digest;

it('accepts a well-formed sha256 digest', function () {
    Digest::assertValid('sha256:'.str_repeat('a', 64));
})->throwsNoExceptions();

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

<?php

namespace App\Services\Vcs;

use RuntimeException;

/**
 * Internal control-flow signal for GitRepository::fileAtRefCapped().
 *
 * Symfony's process runner has no "stop here" return value for an output callback, so the
 * only way to abandon a `git show` mid-stream — rather than let it keep filling a buffer
 * with a blob we have already decided is too large — is to throw out of the callback. This
 * class exists so that abort is distinguishable from a genuine process failure and never
 * escapes GitRepository.
 *
 * @internal
 */
class BlobCapReached extends RuntimeException {}

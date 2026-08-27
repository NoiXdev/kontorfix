<?php

namespace App\Services\Vcs;

use RuntimeException;

/**
 * GitRepository::sync() gave up waiting for another sync of the same mirror.
 *
 * A distinct type rather than a plain RuntimeException because the two callers of sync()
 * have to answer it differently, and only one of them can tell "the mirror is busy" from
 * "the clone failed" by any other means:
 *
 * - SyncPackage records the message and rethrows, so the queue retries it — for the job the
 *   distinction changes nothing.
 * - ComposerController::dist() has no retry behind it. It turns this into 503 +
 *   `Retry-After`, which says "come back", while every other RuntimeException from sync()
 *   stays a 500, which says "this is broken". Matching on the message text instead would
 *   have coupled an HTTP status to a German sentence.
 *
 * The message is carried here rather than at the throw site so both the queue's
 * `sync_error` column and the 503 body say the same thing.
 */
class MirrorLockBusy extends RuntimeException
{
    public function __construct()
    {
        // German: reaches the operator through `packages.sync_error`, and a Composer client
        // through the 503 body. Deliberately neutral about who retries — SyncPackage does it
        // automatically, a dist request does not.
        parent::__construct(
            'Ein anderer Vorgang synchronisiert gerade den Git-Mirror dieses Pakets. '
            .'Dieser Sync wurde abgebrochen, statt parallel dazu zu laufen — '
            .'nach dem Ende des laufenden Vorgangs erneut versuchen.'
        );
    }
}

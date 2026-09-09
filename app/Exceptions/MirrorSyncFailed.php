<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain exception for a failed mirror import (App\Services\Mirror\MirrorImporter and its
 * per-type collaborators). The message is German and meant to be shown verbatim as
 * Package::sync_error — every failure path in the mirror pipeline throws this rather than a
 * lower-level exception (UpstreamException, etc.), so nothing downstream has to translate
 * or paraphrase an English internal error for the operator.
 */
class MirrorSyncFailed extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}

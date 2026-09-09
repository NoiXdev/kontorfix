<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised by UpstreamClient on a non-2xx upstream response or a transport-level refusal
 * (unsafe redirect target, missing Location header, too many redirects).
 *
 * $status carries the upstream's HTTP status code when the failure was a response (never
 * for a transport-level refusal, which has no status to report) — a language-neutral fact
 * a caller can fold into its own message. Callers that need to surface a failure to an
 * operator (e.g. MirrorSyncFailed) read $status rather than $this->getMessage(): the
 * message here is English prose meant for logs/exceptions, and splicing it into an
 * otherwise-German operator-facing string would produce half-German, half-English text.
 */
class UpstreamException extends RuntimeException
{
    public function __construct(string $message, private readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function status(): ?int
    {
        return $this->status;
    }
}

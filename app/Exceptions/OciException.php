<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * The OCI distribution error body, stated once.
 *
 * A Docker client prints `errors[].message` straight to the user's terminal, so those
 * strings are user-facing text and follow this project's German UI convention — unlike
 * `code`, which is part of the protocol and stays in the spec's uppercase form.
 */
final class OciException extends Exception
{
    /**
     * Named `$errorCode`, not `$code`: `Exception` already declares a (non-readonly, int)
     * `$code` property, and PHP refuses to redeclare an inherited property as readonly —
     * `final class OciException extends Exception { ... private readonly string $code ... }`
     * is a fatal "Cannot redeclare non-readonly property Exception::$code as readonly",
     * caught only by actually running this class rather than reading it.
     *
     * @param  array<string, string>  $headers
     */
    private function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function unauthorized(): self
    {
        // The WWW-Authenticate header is what makes `docker login` send credentials at all.
        // Without it the client reports "no basic auth credentials" and never asks the user.
        return new self(401, 'UNAUTHORIZED', 'Anmeldung erforderlich.', [
            'WWW-Authenticate' => 'Basic realm="kontorfix"',
        ]);
    }

    public static function denied(): self
    {
        return new self(403, 'DENIED', 'Dieses Token darf hier nicht schreiben.');
    }

    public static function nameUnknown(string $name): self
    {
        return new self(404, 'NAME_UNKNOWN', "Das Repository {$name} gibt es in dieser Registry nicht.");
    }

    public static function manifestUnknown(string $reference): self
    {
        return new self(404, 'MANIFEST_UNKNOWN', "Weder Tag noch Digest {$reference} sind bekannt.");
    }

    public static function blobUnknown(string $digest): self
    {
        return new self(404, 'BLOB_UNKNOWN', "Der Layer {$digest} liegt nicht in dieser Registry.");
    }

    public static function digestInvalid(string $expected, string $actual): self
    {
        return new self(400, 'DIGEST_INVALID', "Der hochgeladene Inhalt ergibt {$actual}, angekündigt war {$expected}.");
    }

    public static function unsupported(string $message): self
    {
        return new self(400, 'UNSUPPORTED', $message);
    }

    public function render(): JsonResponse
    {
        return response()->json(
            ['errors' => [['code' => $this->errorCode, 'message' => $this->getMessage(), 'detail' => null]]],
            $this->status,
            $this->headers + ['Docker-Distribution-Api-Version' => 'registry/2.0'],
        );
    }
}

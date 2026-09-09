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

    /**
     * The one refusal the push path uses while `oci_auto_create_repositories` is off — for a
     * free name, for a name this organization owns but has not assigned to this registry, and
     * for a name another organization holds alike. ResolvesOciRepository::ociUnresolvedRepository()
     * says why all three share it.
     *
     * The CODE stays NAME_UNKNOWN — it is protocol, a client branches on it, and nothing
     * about the situation differs from an unknown name. Only the human half changes, and
     * it names the setting: without that, the one person who can fix this in a checkbox
     * reads "gibt es nicht" and goes looking in the logs instead.
     *
     * Both remedies are named because the message has to fit all three shapes: assigning the
     * repository to this registry is what helps for the second, the setting for the first. A
     * message that named only one would be wrong for the other two shapes — and, worse, would
     * have to differ between them, which is the leak this single message exists to close.
     */
    public static function nameUnknownAutoCreateDisabled(string $name): self
    {
        return new self(404, 'NAME_UNKNOWN', "Das Repository {$name} gibt es in dieser Registry nicht. "
            .'Neue Repositories beim Push anzulegen ist deaktiviert — legen Sie das Repository in der '
            .'Verwaltung an und weisen Sie es dieser Registry zu, oder aktivieren Sie '
            .'„Repositories beim Push anlegen“ in den Systemeinstellungen.');
    }

    /**
     * A repository name this registry cannot store, refused BEFORE anything is written.
     *
     * The OCI grammar bounds the shape of a name and not its length, and `packages.name` is
     * varchar(255): with push-time creation on, a longer name reached Package::create() and
     * raised SQLSTATE[22001], which nothing renders — an HTML 500 rather than an `errors[]`
     * envelope, and a stack trace per request in the log. NAME_INVALID is the code the OCI
     * spec registers for exactly this ("Invalid repository name encountered"), and 400 says
     * what is true: the caller may make this request, the name itself is the problem, and no
     * amount of retrying or authenticating changes that.
     */
    public static function nameInvalid(string $name, int $limit): self
    {
        return new self(400, 'NAME_INVALID', 'Der Repository-Name ist mit '.mb_strlen($name)." Zeichen zu lang; erlaubt sind höchstens {$limit}.");
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

    /**
     * A manifest body above ManifestController's own ceiling.
     *
     * 413 rather than 400: the request is well-formed and the caller is entitled to make
     * it — only its size is refused — and 413 is the one status a client can act on
     * without parsing the body. MANIFEST_INVALID is the OCI-registered code for a manifest
     * this registry will not accept; the spec defines no code for "too large".
     */
    public static function manifestTooLarge(int $limit): self
    {
        return new self(413, 'MANIFEST_INVALID', "Das Manifest überschreitet die Obergrenze von {$limit} Bytes.");
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

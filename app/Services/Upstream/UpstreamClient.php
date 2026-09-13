<?php

namespace App\Services\Upstream;

use App\Exceptions\UpstreamException;
use App\Support\CredentialUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class UpstreamClient
{
    /**
     * @param  array<string, string>  $headers  Additional request headers that OVERRIDE the
     *                                          default `Accept: application/json` set by
     *                                          acceptJson() — e.g. a caller wanting PyPI's
     *                                          `application/vnd.pypi.simple.v1+json` passes
     *                                          `['Accept' => '...']` here and the wire request
     *                                          carries exactly that value, not both. Must use
     *                                          replaceHeaders() (true override), not
     *                                          withHeaders() (array_merge_recursive — for a
     *                                          key already set by acceptJson() that appends a
     *                                          second value onto the same header instead of
     *                                          replacing it).
     * @return array<string, mixed>|null null on 404
     */
    public function getJson(UpstreamEndpoint $endpoint, string $path, array $headers = []): ?array
    {
        $url = rtrim($endpoint->endpointUrl(), '/').'/'.ltrim($path, '/');

        // Like getBytes: follow redirects manually and re-check each hop against the
        // SSRF rules — a malicious upstream must not be able to redirect a metadata
        // fetch via 302 to an internal address (http://[::1]/, 169.254.169.254).
        $cap = self::metadataCap();

        [$response] = $this->follow($endpoint, $url, function (PendingRequest $req) use ($headers, $cap): PendingRequest {
            $req = $headers === [] ? $req->acceptJson() : $req->acceptJson()->replaceHeaders($headers);

            return $req->withOptions([
                // The body lands in a temp stream instead of a PHP string. Without this,
                // $response->json() was the first thing that saw the response, and by then
                // the whole document was already resident — a decode spike an upstream
                // chooses the size of. php://temp spills to disk past its threshold, so
                // memory stays bounded whatever arrives.
                'sink' => self::metadataSink(),
                // …and a declared oversize body is refused before it is sent at all.
                // Only advisory: it acts on Content-Length, which a chunked response
                // simply omits, which is why the read below caps as well.
                'curl' => [CURLOPT_MAXFILESIZE => $cap],
            ]);
        });

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new UpstreamException('Upstream '.CredentialUrl::redact($endpoint->endpointUrl())." returned {$response->status()} for {$path}.", $response->status());
        }

        $body = $response->toPsrResponse()->getBody();
        $body->rewind();
        $json = $body->read($cap + 1);

        if (strlen($json) > $cap) {
            throw new UpstreamException('Upstream '.CredentialUrl::redact($endpoint->endpointUrl())." answered with more than {$cap} bytes of metadata for {$path}.");
        }

        $decoded = json_decode($json, true);

        // Matches what Response::json() did before: a body that is not a JSON object is
        // indistinguishable from an absent one to every caller here.
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * No production caller: every serving path goes through getStream(), which caps and
     * hashes while the bytes arrive. It is kept because the SSRF suite drives it as the
     * plainest probe of follow()'s redirect and bearer-scoping rules, and rewriting those
     * regression tests around a streaming return would obscure what they pin.
     *
     * It buffers the whole body in a PHP string and has no cap. That is tolerable only
     * while nothing in production calls it: a serving path that needs artifact bytes takes
     * getStream() and its byte cap, and this must not become the shortcut that skips them.
     */
    public function getBytes(UpstreamEndpoint $endpoint, string $absoluteUrl): ?string
    {
        [$response] = $this->follow($endpoint, $absoluteUrl, fn (PendingRequest $req) => $req);

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new UpstreamException('Upstream artifact '.CredentialUrl::redact($absoluteUrl)." returned {$response->status()}.", $response->status());
        }

        return $response->body();
    }

    /**
     * The artifact as a readable stream rather than a string.
     *
     * getBytes() materialises the whole artifact in PHP memory before anybody can look at
     * its size, so the 100 MiB per-artifact cap could never be reached on the shipped
     * 128 M memory_limit: an oversize artifact killed the worker instead of being declined.
     * The cap belongs where the bytes arrive, and that needs a stream.
     *
     * The declared Content-Length travels with the stream, because the consumer cannot
     * otherwise tell a complete body from a truncated one: with `['stream' => true]` and
     * `allow_url_fopen` on — the shipped configuration — Guzzle uses the StreamHandler,
     * where a mid-body stall simply stops producing bytes instead of raising. A chunked
     * upstream declares no length; null says so rather than pretending to zero.
     *
     * @return array{stream: resource, length: int|null}|null null on 404
     */
    public function getStream(UpstreamEndpoint $endpoint, string $absoluteUrl): ?array
    {
        [$response, $pin] = $this->follow(
            $endpoint,
            $absoluteUrl,
            fn (PendingRequest $req) => $req->withOptions(['stream' => true]),
            streaming: true,
        );

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new UpstreamException('Upstream artifact '.CredentialUrl::redact($absoluteUrl)." returned {$response->status()}.", $response->status());
        }

        $declared = $response->header('Content-Length');
        $stream = $response->toPsrResponse()->getBody()->detach();

        if (! is_resource($stream)) {
            return null;
        }

        // The one place the artifact path can still be rebound. CURLOPT_RESOLVE does not
        // reach here: `stream => true` routes this request to PHP's stream wrapper, which
        // takes its connect address from the URL and offers no override. So the peer is
        // checked against the pin instead — after the socket is open, but before a single
        // body byte is read, which is the point that matters: these bytes get persisted
        // as an artifact and streamed back to a tenant.
        //
        // A non-socket resource means libcurl handled the request after all (a deployment
        // with allow_url_fopen off), where follow() has already pinned the connection and
        // there is no peer to read. Only a socket is checked, and only a socket can lie.
        $peer = self::peerOf($stream);
        if ($peer !== null && ! $pin->permits($peer)) {
            fclose($stream);

            throw new UpstreamException('Upstream '.CredentialUrl::redact($absoluteUrl).' connected to an address that did not pass the outbound address policy.');
        }

        return [
            'stream' => $stream,
            'length' => is_numeric($declared) ? (int) $declared : null,
        ];
    }

    /**
     * Follow redirects manually (max 5) and re-check EACH hop against the SSRF
     * rules — Packagist dists legitimately point to GitHub, which redirects via 302
     * to another host (codeload/objects.githubusercontent); a malicious upstream must
     * not be able to use that to redirect to an internal address.
     *
     * The bearer token is sent exclusively to the original upstream host: on a
     * redirect to a different host, the private token must not travel along with it
     * (otherwise a malicious upstream could harvest it via 302 to its own collector).
     *
     * Each hop is pinned to the addresses its own safety check was reached on, so the
     * transport never re-resolves a name we already judged (see AddressPin). The pin of
     * the FINAL hop travels back to the caller, because that is the connection whose peer
     * the streaming path still has to verify for itself.
     *
     * @param  callable(PendingRequest): PendingRequest  $configure
     * @return array{0: Response, 1: AddressPin}
     */
    private function follow(UpstreamEndpoint $endpoint, string $url, callable $configure, bool $streaming = false): array
    {
        for ($hop = 0; $hop < 5; $hop++) {
            $pin = AddressPin::for($url);
            if ($pin === null) {
                throw new UpstreamException('Refusing unsafe upstream URL: '.CredentialUrl::redact($url).'.');
            }

            // Same host AND an encrypted hop — see request().
            $withAuth = $this->sameHost($url, $endpoint->endpointUrl()) && self::isEncrypted($url);
            $req = $configure($this->request($endpoint, $withAuth));

            // Guzzle chooses the handler by request option: `stream => true` goes to the
            // stream wrapper whenever allow_url_fopen is on, and handing curl options to
            // that handler is both useless and deprecated. Everything else is curl's.
            $curlOptions = $pin->curlOptions();
            if ($curlOptions !== [] && ! ($streaming && filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL))) {
                $req = $req->withOptions(['curl' => $curlOptions]);
            }

            $response = $req->withoutRedirecting()->get($url);

            if ($response->redirect()) {
                $location = (string) $response->header('Location');
                if ($location === '') {
                    throw new UpstreamException('Upstream redirect without a Location header from '.CredentialUrl::redact($url).'.');
                }
                $url = $location;

                continue;
            }

            return [$response, $pin];
        }

        throw new UpstreamException('Too many redirects fetching upstream URL '.CredentialUrl::redact($url).'.');
    }

    /**
     * The peer address of an open socket, or null when the resource is not a socket.
     *
     * Null means "not applicable", not "allowed": it is returned only for a resource that
     * has no remote end to report — a curl sink, a memory stream — and the caller treats
     * that case as already pinned by CURLOPT_RESOLVE. A socket whose peer cannot be read
     * reports the empty string rather than null, which permits() then refuses.
     *
     * @param  resource  $stream
     */
    private static function peerOf($stream): ?string
    {
        $meta = stream_get_meta_data($stream);
        $type = $meta['stream_type'];

        if (! str_starts_with($type, 'tcp_socket')) {
            return null;
        }

        $peer = @stream_socket_get_name($stream, true);

        return is_string($peer) ? $peer : '';
    }

    private static function metadataCap(): int
    {
        return max(1, (int) config('kontorfix.upstream_max_metadata_bytes'));
    }

    /**
     * Where a metadata response is buffered.
     *
     * php://temp keeps the first 2 MiB in memory and spills the rest to a temp file that
     * is released with the stream, so the size of the response is the upstream's choice
     * but the memory cost is not.
     *
     * @return resource
     */
    private static function metadataSink()
    {
        $sink = fopen('php://temp', 'w+b');

        if (! is_resource($sink)) {
            throw new UpstreamException('Could not allocate a buffer for the upstream response.');
        }

        return $sink;
    }

    private function request(UpstreamEndpoint $endpoint, bool $withAuth = true): PendingRequest
    {
        $req = Http::timeout(30)->connectTimeout(10);
        $token = $endpoint->endpointToken();
        if ($withAuth && $token) {
            $req = $req->withToken($token);
        }

        return $req;
    }

    /**
     * The mirror credential is a bearer token: anyone who observes it can reuse it. Over
     * plain http it travels in cleartext to every device on the path, so it is simply not
     * attached — matching what GitAuth already does for a stored git token on a non-HTTPS
     * remote. The upstream URL rules still permit http (an internal mirror without TLS is
     * a legitimate setup); what is refused is pairing that with a secret.
     *
     * Public and static: App\Services\Mirror\MirrorProbe asks this exact question of a
     * MirrorSource's URL to warn that its token will not be sent, before this class itself
     * ever gets a request to make — the scheme check lives here once rather than being
     * re-derived a second time.
     */
    public static function isEncrypted(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    /**
     * Host comparison for auth forwarding: case-insensitive, including port
     * (default port per scheme). Prevents a redirect to the same host with a
     * different port from grabbing the token.
     */
    private function sameHost(string $a, string $b): bool
    {
        return $this->hostKey($a) === $this->hostKey($b);
    }

    private function hostKey(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);
        if ($port === null) {
            $port = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : 0);
        }

        return $host.':'.$port;
    }
}

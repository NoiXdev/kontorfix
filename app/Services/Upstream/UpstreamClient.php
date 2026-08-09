<?php

namespace App\Services\Upstream;

use App\Exceptions\UpstreamException;
use App\Models\Upstream;
use App\Support\CredentialUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class UpstreamClient
{
    /**
     * @return array<string, mixed>|null null on 404
     */
    public function getJson(Upstream $upstream, string $path): ?array
    {
        $url = rtrim($upstream->url, '/').'/'.ltrim($path, '/');

        // Like getBytes: follow redirects manually and re-check each hop against the
        // SSRF rules — a malicious upstream must not be able to redirect a metadata
        // fetch via 302 to an internal address (http://[::1]/, 169.254.169.254).
        $response = $this->follow($upstream, $url, fn (PendingRequest $req) => $req->acceptJson());

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new UpstreamException('Upstream '.CredentialUrl::redact($upstream->url)." returned {$response->status()} for {$path}.");
        }

        return $response->json();
    }

    public function getBytes(Upstream $upstream, string $absoluteUrl): ?string
    {
        $response = $this->follow($upstream, $absoluteUrl, fn (PendingRequest $req) => $req);

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new UpstreamException("Upstream artifact {$absoluteUrl} returned {$response->status()}.");
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
     * @return resource|null null on 404
     */
    public function getStream(Upstream $upstream, string $absoluteUrl)
    {
        $response = $this->follow(
            $upstream,
            $absoluteUrl,
            fn (PendingRequest $req) => $req->withOptions(['stream' => true]),
        );

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new UpstreamException("Upstream artifact {$absoluteUrl} returned {$response->status()}.");
        }

        $stream = $response->toPsrResponse()->getBody()->detach();

        return is_resource($stream) ? $stream : null;
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
     * @param  callable(PendingRequest): PendingRequest  $configure
     */
    private function follow(Upstream $upstream, string $url, callable $configure): Response
    {
        for ($hop = 0; $hop < 5; $hop++) {
            if (! UrlSafety::isSafeResolving($url)) {
                throw new UpstreamException('Refusing unsafe upstream URL: '.CredentialUrl::redact($url).'.');
            }

            // Same host AND an encrypted hop — see request().
            $withAuth = $this->sameHost($url, $upstream->url) && $this->isEncrypted($url);
            $response = $configure($this->request($upstream, $withAuth))->withoutRedirecting()->get($url);

            if ($response->redirect()) {
                $location = (string) $response->header('Location');
                if ($location === '') {
                    throw new UpstreamException('Upstream redirect without a Location header from '.CredentialUrl::redact($url).'.');
                }
                $url = $location;

                continue;
            }

            return $response;
        }

        throw new UpstreamException('Too many redirects fetching upstream URL '.CredentialUrl::redact($url).'.');
    }

    private function request(Upstream $upstream, bool $withAuth = true): PendingRequest
    {
        $req = Http::timeout(30)->connectTimeout(10);
        if ($withAuth && $upstream->auth_token) {
            $req = $req->withToken($upstream->auth_token);
        }

        return $req;
    }

    /**
     * The mirror credential is a bearer token: anyone who observes it can reuse it. Over
     * plain http it travels in cleartext to every device on the path, so it is simply not
     * attached — matching what GitAuth already does for a stored git token on a non-HTTPS
     * remote. The upstream URL rules still permit http (an internal mirror without TLS is
     * a legitimate setup); what is refused is pairing that with a secret.
     */
    private function isEncrypted(string $url): bool
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

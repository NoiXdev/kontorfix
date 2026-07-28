<?php

namespace App\Services\Upstream;

use App\Exceptions\UpstreamException;
use App\Models\Upstream;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class UpstreamClient
{
    /**
     * @return array<string, mixed>|null null bei 404
     */
    public function getJson(Upstream $upstream, string $path): ?array
    {
        $url = rtrim($upstream->url, '/').'/'.ltrim($path, '/');

        // Wie getBytes: Redirects manuell folgen und jeden Hop erneut gegen die
        // SSRF-Regeln prüfen — ein bösartiger Upstream darf einen Metadaten-Abruf
        // nicht per 302 auf eine interne Adresse (http://[::1]/, 169.254.169.254) lenken.
        $response = $this->follow($upstream, $url, fn (PendingRequest $req) => $req->acceptJson());

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new UpstreamException("Upstream {$upstream->url} returned {$response->status()} for {$path}.");
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
     * Redirects manuell folgen (max. 5) und JEDEN Hop erneut gegen die SSRF-Regeln
     * prüfen — packagist-Dists zeigen legitim auf GitHub, das per 302 auf einen anderen
     * Host (codeload/objects.githubusercontent) weiterleitet; ein bösartiger Upstream
     * dürfte darüber aber nicht auf eine interne Adresse umlenken.
     *
     * Das Bearer-Token wird ausschließlich an den ursprünglichen Upstream-Host gesendet:
     * bei einem Redirect auf einen fremden Host darf das private Token nicht mitwandern
     * (sonst erntet ein bösartiger Upstream es per 302 auf einen eigenen Collector).
     *
     * @param  callable(PendingRequest): PendingRequest  $configure
     */
    private function follow(Upstream $upstream, string $url, callable $configure): Response
    {
        for ($hop = 0; $hop < 5; $hop++) {
            if (! UrlSafety::isSafeResolving($url)) {
                throw new UpstreamException("Refusing unsafe upstream URL: {$url}.");
            }

            $withAuth = $this->sameHost($url, $upstream->url);
            $response = $configure($this->request($upstream, $withAuth))->withoutRedirecting()->get($url);

            if ($response->redirect()) {
                $location = (string) $response->header('Location');
                if ($location === '') {
                    throw new UpstreamException("Upstream redirect without a Location header from {$url}.");
                }
                $url = $location;

                continue;
            }

            return $response;
        }

        throw new UpstreamException("Too many redirects fetching upstream URL {$url}.");
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
     * Host-Vergleich für die Auth-Weitergabe: case-insensitiv, inklusive Port
     * (Standard-Port je Schema). Verhindert, dass ein Redirect auf denselben Host mit
     * anderem Port das Token abgreift.
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

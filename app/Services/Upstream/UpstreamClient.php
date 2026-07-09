<?php

namespace App\Services\Upstream;

use App\Exceptions\UpstreamException;
use App\Models\Upstream;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class UpstreamClient
{
    /**
     * @return array<string, mixed>|null null bei 404
     */
    public function getJson(Upstream $upstream, string $path): ?array
    {
        $url = rtrim($upstream->url, '/').'/'.ltrim($path, '/');
        $response = $this->request($upstream)->acceptJson()->get($url);

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
        // Redirects manuell folgen und JEDEN Hop erneut gegen die SSRF-Regeln prüfen —
        // packagist-Dists zeigen legitim auf GitHub, das per 302 auf einen anderen Host
        // (codeload/objects.githubusercontent) weiterleitet; ein bösartiger Upstream
        // dürfte darüber aber nicht auf eine interne Adresse umlenken.
        $url = $absoluteUrl;

        for ($hop = 0; $hop < 5; $hop++) {
            if (! UrlSafety::isSafeResolving($url)) {
                throw new UpstreamException("Refusing unsafe upstream artifact URL: {$url}.");
            }

            $response = $this->request($upstream)->withoutRedirecting()->get($url);

            if ($response->status() === 404) {
                return null;
            }
            if ($response->redirect()) {
                $location = $response->header('Location');
                if ($location === '') {
                    throw new UpstreamException("Upstream artifact redirect without a Location header from {$url}.");
                }
                $url = $location;

                continue;
            }
            if (! $response->successful()) {
                throw new UpstreamException("Upstream artifact {$url} returned {$response->status()}.");
            }

            return $response->body();
        }

        throw new UpstreamException("Too many redirects fetching upstream artifact {$absoluteUrl}.");
    }

    private function request(Upstream $upstream): PendingRequest
    {
        $req = Http::timeout(30)->connectTimeout(10);
        if ($upstream->auth_token) {
            $req = $req->withToken($upstream->auth_token);
        }

        return $req;
    }
}

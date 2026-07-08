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
        // Keine Redirects folgen — sonst könnte ein Upstream via 302 auf eine interne
        // Adresse umleiten und die vorgelagerte URL-Prüfung umgehen.
        $response = $this->request($upstream)->withoutRedirecting()->get($absoluteUrl);
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new UpstreamException("Upstream artifact {$absoluteUrl} returned {$response->status()}.");
        }

        return $response->body();
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

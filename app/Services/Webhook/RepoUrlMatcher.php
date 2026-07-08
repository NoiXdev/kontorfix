<?php

namespace App\Services\Webhook;

use App\Models\Package;
use Illuminate\Database\Eloquent\Collection;

class RepoUrlMatcher
{
    public function normalize(string $url): string
    {
        $url = trim($url);
        $url = (string) preg_replace('#^git@([^:]+):#', '$1/', $url);      // scp-style ssh
        $url = (string) preg_replace('#^[a-z0-9+]+://#i', '', $url);        // scheme
        $url = (string) preg_replace('#^[^/@]*@#', '', $url);               // user@host
        $url = (string) preg_replace('#\.git$#', '', $url);
        $url = rtrim($url, '/');

        // Host case-insensitiv, Pfad case-SENSITIV — auf case-sensitiven Git-Hostern sind
        // /Acme/Demo und /acme/demo verschiedene Repos.
        if (str_contains($url, '/')) {
            [$host, $path] = explode('/', $url, 2);

            return strtolower($host).'/'.$path;
        }

        return strtolower($url);
    }

    /**
     * @return Collection<int, Package>
     *
     * TODO(scale): lädt den ganzen Paket-Pool und filtert in PHP (O(n) pro Webhook).
     * Bei größerem Pool eine normalisierte, indizierte repository_url-Spalte einführen.
     */
    public function match(string $repoUrl): Collection
    {
        $norm = $this->normalize($repoUrl);

        return Package::whereNotNull('repository_url')->get()
            ->filter(fn (Package $p) => $this->normalize((string) $p->repository_url) === $norm)
            ->values();
    }
}

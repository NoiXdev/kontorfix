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

        // Host case-insensitive, path case-SENSITIVE — on case-sensitive git hosts,
        // /Acme/Demo and /acme/demo are different repos.
        if (str_contains($url, '/')) {
            [$host, $path] = explode('/', $url, 2);

            return strtolower($host).'/'.$path;
        }

        return strtolower($url);
    }

    /**
     * @return Collection<int, Package>
     *
     * TODO(scale): loads the entire package pool and filters in PHP (O(n) per webhook).
     * Introduce a normalized, indexed repository_url column for a larger pool.
     */
    public function match(string $repoUrl): Collection
    {
        $norm = $this->normalize($repoUrl);

        return Package::whereNotNull('repository_url')->get()
            ->filter(fn (Package $p) => $this->normalize((string) $p->repository_url) === $norm)
            ->values();
    }
}

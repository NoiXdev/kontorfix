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

        return strtolower(rtrim($url, '/'));
    }

    /** @return Collection<int, Package> */
    public function match(string $repoUrl): Collection
    {
        $norm = $this->normalize($repoUrl);

        return Package::whereNotNull('repository_url')->get()
            ->filter(fn (Package $p) => $this->normalize((string) $p->repository_url) === $norm)
            ->values();
    }
}

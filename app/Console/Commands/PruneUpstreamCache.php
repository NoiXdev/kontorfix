<?php

namespace App\Console\Commands;

use App\Services\Upstream\UpstreamCache;
use Illuminate\Console\Command;

/**
 * Keeps the proxy artifact cache under its budget over time. Without this the budget
 * would be a one-way wall: once reached, every proxied download would fall back to a
 * pass-through fetch forever, and only an operator deleting files by hand would fix it.
 */
class PruneUpstreamCache extends Command
{
    protected $signature = 'upstream-cache:prune {--days= : Höchstalter in Tagen (Default aus der Konfiguration)}';

    protected $description = 'Entfernt Proxy-Artefakte, die länger als das konfigurierte Höchstalter nicht abgerufen wurden.';

    public function handle(UpstreamCache $cache): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('kontorfix.upstream_cache_prune_days', 30);

        if ($days < 1) {
            $this->error('Das Höchstalter muss mindestens 1 Tag betragen.');

            return self::FAILURE;
        }

        $deleted = $cache->pruneArtifacts($days);

        $this->info("{$deleted} Proxy-Artefakt(e) älter als {$days} Tage entfernt.");

        return self::SUCCESS;
    }
}

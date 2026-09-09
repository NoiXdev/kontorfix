<?php

namespace App\Console\Commands;

use App\Services\Oci\Sweeper\OciSweeper;
use Illuminate\Console\Command;

/**
 * The scheduled storage sweep. Synchronous, so `withoutOverlapping()` on the schedule
 * really does prevent a second concurrent scheduled run — and so an operator in a shell
 * gets output. The admin page's button dispatches App\Jobs\SweepOciStorage instead, and the
 * two CAN overlap; every delete in OciSweeper is idempotent for exactly that reason.
 */
class SweepOciStorage extends Command
{
    protected $signature = 'oci:sweep {--limit= : Höchstzahl der pro Lauf entfernten Blobs (Default aus der Konfiguration)}';

    protected $description = 'Entfernt unerreichbare Image-Daten: Manifeste, Blobs, abgelaufene Upload-Sessions und leere, beim Push angelegte Repositories.';

    public function handle(OciSweeper $sweeper): int
    {
        $limit = $this->option('limit') !== null
            ? (int) $this->option('limit')
            : (int) config('kontorfix.oci_sweep_blob_limit', 1000);

        if ($limit < 1) {
            $this->error('Das Budget muss mindestens 1 Blob betragen.');

            return self::FAILURE;
        }

        $report = $sweeper->sweep($limit);

        $this->info(sprintf(
            '%d Manifest(e), %d Blob(s) (%d Byte), %d Upload-Session(s), %d leere(s) Repository/Repositories entfernt.',
            $report->manifestsRemoved,
            $report->blobsRemoved,
            $report->bytesReclaimed,
            $report->uploadsRemoved,
            $report->repositoriesRemoved,
        ));

        $this->line(sprintf('%d Blob(s) werden derzeit von der Schonfrist gehalten.', $report->blobsHeldByGrace));

        if ($report->blobsRemaining > 0) {
            // A bounded run says it was bounded. Silent truncation reads as "everything is
            // clean", which is exactly wrong for a first sweep of a registry that has never
            // been swept.
            $this->warn(sprintf(
                'Budget erreicht: %d weitere unerreichbare Blob(s) bleiben für den nächsten Lauf.',
                $report->blobsRemaining,
            ));
        }

        return self::SUCCESS;
    }
}

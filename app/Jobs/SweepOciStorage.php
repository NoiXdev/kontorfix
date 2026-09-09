<?php

namespace App\Jobs;

use App\Services\Oci\Sweeper\OciSweeper;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The admin page's "Jetzt bereinigen" button. A queued job because a sweep moves files and
 * can take minutes — an HTTP request must not be held open for it. ShouldBeUnique is what
 * prevents two queued manual sweeps, not `withoutOverlapping()`, which only guards the
 * scheduler's own dispatch (the caveat routes/console.php records for Schedule::job()).
 *
 * A queued run CAN overlap the scheduled `oci:sweep` command — deliberately tolerated
 * rather than prevented, because every delete in OciSweeper is idempotent: the second run
 * finds less work, it does not corrupt the first.
 */
class SweepOciStorage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Declared, never inherited: config/horizon.php raises the supervisor timeout to
     * SyncPackage's 900s, and Worker::timeoutForJob() hands that value to any job that
     * declares none (the drift tests/Unit/SyncTimingRelationsTest.php exists to refuse).
     * Sized for the budgeted sweep it runs: 1000 blob deletes on a local disk are seconds,
     * on S3 a network round-trip each — ten minutes covers the slow case with room, while
     * a sweep that genuinely exceeds it has hung on the disk and SHOULD be killed; the
     * next scheduled run resumes exactly where the budget left off, because every delete
     * is idempotent and blobsRemaining carries over by construction.
     */
    public int $timeout = 600;

    public function handle(OciSweeper $sweeper): void
    {
        $sweeper->sweep((int) config('kontorfix.oci_sweep_blob_limit', 1000));
    }
}

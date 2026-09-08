<?php

namespace App\Console\Commands;

use App\Enums\PackageType;
use App\Models\Package;
use App\Services\Oci\Retention\RetentionRunner;
use Illuminate\Console\Command;

/**
 * The scheduled retention run. Synchronous, so `withoutOverlapping()` on the schedule
 * really does prevent a second concurrent run — the caveat routes/console.php records
 * about `Schedule::job()` does not apply here.
 */
class ApplyOciRetention extends Command
{
    protected $signature = 'oci:retention
        {--dry-run : Nur berichten, nichts entfernen}
        {--package= : Nur dieses Repository (ID)}';

    protected $description = 'Wendet die Aufbewahrungsregeln auf Image-Repositories an.';

    public function handle(RetentionRunner $runner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $removed = 0;
        $touched = 0;

        $query = Package::query()->where('type', PackageType::Docker);

        if (is_string($this->option('package'))) {
            $query->whereKey($this->option('package'));
        }

        foreach ($query->lazyById() as $package) {
            $report = $dryRun ? $runner->dryRun($package) : $runner->apply($package);

            if ($report === null || $report->removed() === []) {
                continue;
            }

            $touched++;
            $removed += count($report->removed());

            $this->line(sprintf(
                '%s: %d Tag(s) %s (%s)',
                $package->name,
                count($report->removed()),
                $dryRun ? 'würden entfernt' : 'entfernt',
                $report->resolution->label(),
            ));
        }

        $this->info(sprintf(
            '%d Tag(s) in %d Repository/Repositories %s.',
            $removed,
            $touched,
            $dryRun ? 'würden entfernt' : 'entfernt',
        ));

        // Said on every run, not only when something was removed: an operator who watches
        // tags disappear while used storage stays flat would otherwise file that as a bug
        // against the sweeper. Retention deletes tag rows; the bytes come back when
        // `oci:sweep` collects what those tags reached, after the grace period.
        $this->comment('Speicherplatz wird erst von `oci:sweep` freigegeben, nach Ablauf der Schonfrist.');

        return self::SUCCESS;
    }
}

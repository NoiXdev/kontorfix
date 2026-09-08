<?php

namespace App\Console\Commands;

use App\Enums\PackageType;
use App\Models\Package;
use App\Services\Oci\Retention\RetentionRunner;
use App\Support\Retention\CorruptInlineRetentionRules;
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
        $failed = 0;

        $query = Package::query()->where('type', PackageType::Docker);

        if (is_string($this->option('package'))) {
            $query->whereKey($this->option('package'));
        }

        foreach ($query->lazyById() as $package) {
            try {
                $report = $dryRun ? $runner->dryRun($package) : $runner->apply($package);
            } catch (CorruptInlineRetentionRules $e) {
                // Caught PER PACKAGE, deliberately: a corrupt `retention_rules` jsonb must
                // never read as "delete freely" for this package (dryRun()/apply() never
                // ran for it, so nothing was touched), nor abort the run for every OTHER
                // package that has nothing wrong with it. Contrast UntaggedRetention's
                // sweeper path, which lets the same exception type abort instead — see its
                // docblock for why that direction is the safe one there.
                $failed++;
                $this->error(sprintf('%s: %s', $package->name, $e->getMessage()));

                continue;
            }

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

        if ($failed > 0) {
            $this->error(sprintf(
                '%d Repository/Repositories übersprungen: ungültige eigene Regeln, nichts entfernt.',
                $failed,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

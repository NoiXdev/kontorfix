<?php

namespace App\Console\Commands;

use App\Services\Users\EmailUniquenessIndex;
use Illuminate\Console\Command;

/**
 * Re-runs the `lower(email)` uniqueness upgrade after an operator has resolved the
 * case-variant accounts that blocked it at migration time. Without this the degraded
 * (non-unique) state installed by the migration would be permanent, and "deploy-safe"
 * would just mean "quietly weaker forever".
 */
class EnforceEmailUniqueness extends Command
{
    protected $signature = 'users:enforce-email-uniqueness';

    protected $description = 'Install the unique index on lower(users.email), or report the accounts that prevent it';

    public function handle(EmailUniquenessIndex $index): int
    {
        $collisions = $index->collisions();

        if ($collisions !== []) {
            $this->error('Diese Adressen gehören zu mehreren Konten, sobald Groß-/Kleinschreibung ignoriert wird:');
            foreach ($collisions as $email => $count) {
                $this->line("  {$email} ({$count} Konten)");
            }
            $this->newLine();
            $this->warn('Bitte die betroffenen Konten zusammenführen oder löschen und diesen Befehl erneut ausführen.');
            $this->line('Bis dahin bleibt es bei einem nicht-eindeutigen Index; neue Kollisionen weist die Anwendung bereits ab.');

            $index->install();

            return self::FAILURE;
        }

        $index->install();
        $this->info('Eindeutiger Index auf lower(users.email) ist aktiv.');

        return self::SUCCESS;
    }
}

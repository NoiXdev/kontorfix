<?php

namespace App\Console\Commands;

use App\Services\Setup\SetupStatus;
use App\Services\Setup\SetupToken;
use Illuminate\Console\Command;

class SetupTokenCommand extends Command
{
    protected $signature = 'setup:token';

    protected $description = 'Rotate and print the first-run setup token (no-op once the instance is set up).';

    public function handle(SetupStatus $status, SetupToken $token): int
    {
        // Once an account exists the wizard is sealed anyway; drop any stale token.
        if ($status->isComplete()) {
            $token->clear();
            $this->info('Instance already set up — setup token cleared.');

            return self::SUCCESS;
        }

        $value = $token->regenerate();
        $url = rtrim((string) config('app.url'), '/').'/setup?token='.$value;

        // Deliberately loud: this is meant to be found in the container startup logs.
        $this->newLine();
        $this->warn('==================================================================');
        $this->warn(' FIRST-RUN SETUP TOKEN (needed to open the setup wizard)');
        $this->warn(' Token: '.$value);
        $this->warn(' URL:   '.$url);
        $this->warn('==================================================================');
        $this->newLine();

        return self::SUCCESS;
    }
}

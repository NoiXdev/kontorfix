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
        // Bare URL, token on its own line. Putting the token in the URL made every
        // reverse proxy, CDN and APM in the path record a value that grants the whole
        // instance, and put it in the operator's browser history as well. The wizard
        // takes it from a form field and POSTs it instead.
        $url = rtrim((string) config('app.url'), '/').'/setup';

        // Deliberately loud: this is meant to be found in the container startup logs.
        $this->newLine();
        $this->warn('==================================================================');
        $this->warn(' FIRST-RUN SETUP TOKEN (needed to open the setup wizard)');
        $this->warn(' URL:   '.$url);
        $this->warn(' Token: '.$value);
        $this->warn(' Paste the token into the wizard — do not append it to the URL.');
        $this->warn('==================================================================');
        $this->newLine();

        return self::SUCCESS;
    }
}

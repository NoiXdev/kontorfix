<?php

use App\Models\WebhookDelivery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('packages:resync')->hourly()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [WebhookDelivery::class]])->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();

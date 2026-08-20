<?php

use App\Jobs\SendNotificationDigest;
use App\Models\NotificationEventRecord;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('packages:resync')->hourly()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [WebhookDelivery::class]])->daily();
// withoutOverlapping() here only guards the (millisecond) dispatch call, not the queued
// job it enqueues — a run still executing when the next dispatch fires would still get a
// second, concurrent instance queued behind it. Unlike the packages:resync line above,
// where the command itself runs synchronously inside the scheduler process,
// SendNotificationDigest is a queued job, so what actually prevents concurrent execution
// is SendNotificationDigest implementing ShouldBeUnique, not this call.
Schedule::job(new SendNotificationDigest)->hourly()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [NotificationEventRecord::class]])->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
// Keeps the proxied upstream artifact cache under its byte budget over time.
Schedule::command('upstream-cache:prune')->daily()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();

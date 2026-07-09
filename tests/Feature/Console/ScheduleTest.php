<?php

use Illuminate\Support\Facades\Artisan;

it('registers the hourly package resync in the schedule', function () {
    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('packages:resync');
});

it('registers the cleanup and horizon snapshot tasks', function () {
    Artisan::call('schedule:list');
    $out = Artisan::output();
    expect($out)->toContain('model:prune')
        ->and($out)->toContain('queue:prune-failed')
        ->and($out)->toContain('horizon:snapshot');
});

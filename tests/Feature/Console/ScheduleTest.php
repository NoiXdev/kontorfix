<?php

use Illuminate\Support\Facades\Artisan;

it('registers the hourly package resync in the schedule', function () {
    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('packages:resync');
});

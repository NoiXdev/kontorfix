<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('throttles unauthenticated api requests by ip', function () {
    Cache::flush();
    // 240 ungültige Bearer-Requests sind erlaubt-ish; der 241. muss 429 sein (IP-Limit),
    // NICHT 401. Vorher wären alle 401 (unbegrenzt).
    for ($i = 0; $i < 240; $i++) {
        $this->withToken('kfxapi_bad')->getJson('/api/v1/me'); // 401, aber zählt fürs IP-Limit
    }
    $this->withToken('kfxapi_bad')->getJson('/api/v1/me')->assertStatus(429);
});

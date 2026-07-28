<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('throttles unauthenticated api requests by ip', function () {
    Cache::flush();
    // 240 invalid bearer requests are allowed-ish; the 241st must be 429 (IP limit),
    // NOT 401. Before this fix, they would all be 401 (unlimited).
    for ($i = 0; $i < 240; $i++) {
        $this->withToken('kfxapi_bad')->getJson('/api/v1/me'); // 401, but counts toward the IP limit
    }
    $this->withToken('kfxapi_bad')->getJson('/api/v1/me')->assertStatus(429);
});

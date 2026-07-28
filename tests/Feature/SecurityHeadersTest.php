<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets baseline security headers on web responses', function () {
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $res = $this->actingAs($user)->get('/settings/profile');

    $res->assertHeader('X-Content-Type-Options', 'nosniff');
    $res->assertHeader('X-Frame-Options', 'DENY');
    $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($res->headers->get('Permissions-Policy'))->not->toBeNull();
});

it('adds a report-only csp when enabled', function () {
    config(['security.csp_report_only' => true]);
    $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($user)->get('/settings/profile')
        ->assertHeaderContains('Content-Security-Policy-Report-Only', "frame-ancestors 'none'");
});

<?php

use App\Enums\UserRole;
use App\Models\Package;
use App\Models\User;

it('searches the global pool by name fragment', function () {
    Package::factory()->create(['name' => 'kadenz/tickets-core']);
    Package::factory()->create(['name' => 'acme/unrelated']);

    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->getJson('/admin/package-search?q=tick')
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'kadenz/tickets-core');
});

it('returns up to eight results ordered by name', function () {
    foreach (range(1, 12) as $i) {
        Package::factory()->create(['name' => 'acme/pkg-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
    }
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->getJson('/admin/package-search?q=acme')
        ->assertOk()->assertJsonCount(8);
});

it('forbids members from searching', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->getJson('/admin/package-search?q=x')->assertForbidden();
});

it('escapes like wildcards so a percent does not match everything', function () {
    Package::factory()->create(['name' => 'acme/alpha']);
    Package::factory()->create(['name' => 'acme/beta']);
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    // '%' als wörtliches Zeichen gesucht -> kein Paket hat ein '%' im Namen -> 0 Treffer.
    $this->actingAs($admin)->getJson('/admin/package-search?q=%25')->assertOk()->assertJsonCount(0);
    // '_' ebenso wörtlich.
    $this->actingAs($admin)->getJson('/admin/package-search?q=_')->assertOk()->assertJsonCount(0);
});

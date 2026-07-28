<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('blocks robot accounts from interactive password login', function () {
    // Robot mit gesetztem Passwort (Kante): darf sich trotzdem nicht interaktiv anmelden.
    $robot = User::factory()->robot()->create([
        'email' => 'bot@example.test',
        'password' => Hash::make('secret-password'),
    ]);

    $this->post('/login', ['email' => 'bot@example.test', 'password' => 'secret-password'])
        ->assertForbidden();

    $this->assertGuest();
});

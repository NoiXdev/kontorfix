<?php

use App\Models\User;
use App\Notifications\UserInvitation;

it('renders the branded invitation mail with a set-password action', function () {
    $user = User::factory()->create();
    $mail = (new UserInvitation)->toMail($user);
    $rendered = (string) $mail->render();

    expect($rendered)->toContain('Passwort setzen');   // Action-Button-Text
    expect($rendered)->toContain('#D07A45');            // Marken-Kupfer im Theme-CSS inline
    expect($rendered)->not->toBeEmpty();
});

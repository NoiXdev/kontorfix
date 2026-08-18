<?php

use App\Models\User;
use App\Notifications\UserInvitation;

it('renders the branded invitation mail with a set-password action', function () {
    $user = User::factory()->create();
    $mail = (new UserInvitation)->toMail($user);
    $rendered = (string) $mail->render();

    expect($rendered)->toContain('Passwort setzen');   // Action-Button-Text
    // Case-insensitive: prettier normalizes hex literals in the mail theme CSS to lowercase
    // on every `npm run format` run, and CSS hex colors are case-insensitive, so the exact
    // letter case is not a property worth pinning here.
    expect(strtolower($rendered))->toContain('#d07a45'); // Marken-Kupfer im Theme-CSS inline
    expect($rendered)->not->toBeEmpty();
});

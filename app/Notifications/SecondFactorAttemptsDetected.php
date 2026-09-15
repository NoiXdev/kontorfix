<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the account holder that somebody is working through their second factor.
 *
 * It is sent only from the two-factor challenge, never from the password stage, and that
 * is the whole point of the message: reaching this screen means the password was already
 * correct. The operator-facing half of this signal — a log line per guess, a burst entry,
 * a daily ceiling — cannot act on that. Only the owner can change the password.
 *
 * QUEUED, unlike {@see UserInvitation}, which sends inline. That one is triggered by an
 * administrator; this one is triggered by an attacker, as often as they like. Sending inline would put SMTP
 * latency on a request the attacker controls, and would turn a hiccupping mail server into
 * a 500 on a wrong 2FA code.
 *
 * NO ACTION LINK, deliberately. A security warning carrying a login or password-reset link
 * is precisely the shape a phishing mail imitates, and this one arrives exactly when its
 * reader is alarmed and least likely to check. The remedy is named in prose; the user
 * navigates there themselves.
 *
 * Not rate-limited here: the caller fires it from the burst branch of a DAILY counter,
 * which does not roll off with the per-minute window, so it is reachable at most once per
 * account per day however long the attack runs. See TwoFactorChallengeController.
 */
class SecondFactorAttemptsDetected extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        return (new MailMessage)
            ->subject('Ungewöhnliche Anmeldeversuche bei '.config('app.name'))
            ->greeting('Hallo '.$notifiable->name)
            ->line('Bei Ihrem Konto wurde mehrfach ein falscher Bestätigungscode für die '
                .'Zwei-Faktor-Anmeldung eingegeben.')
            ->line('Das bedeutet, dass Ihr Passwort bereits korrekt eingegeben wurde. Die Anmeldung '
                .'ist am zweiten Faktor gescheitert — jemand kennt Ihr Passwort aber offenbar.')
            ->line('Waren Sie das nicht, ändern Sie bitte umgehend Ihr Passwort. Melden Sie sich dazu '
                .'wie gewohnt selbst an; diese E-Mail enthält bewusst keinen Link, damit sie nicht '
                .'für gefälschte Nachrichten missbraucht werden kann.')
            ->line('Waren Sie es selbst, können Sie diese Nachricht ignorieren.');
    }
}

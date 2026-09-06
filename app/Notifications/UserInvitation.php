<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

class UserInvitation extends Notification
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
        $token = Password::broker()->createToken($notifiable);
        $url = url(route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false));

        return (new MailMessage)
            ->subject('Ihr Zugang zu '.config('app.name'))
            ->greeting('Willkommen bei '.config('app.name'))
            ->line('Für Sie wurde ein Zugang angelegt. Setzen Sie jetzt Ihr Passwort:')
            ->action('Passwort setzen', $url)
            ->line('Der Link ist zeitlich begrenzt gültig.');
    }
}

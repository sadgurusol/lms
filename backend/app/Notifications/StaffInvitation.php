<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invites a new staff member to set their password and join the studio. Carries
 * a password-broker token; the link lands on the "set password" page.
 */
class StaffInvitation extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('studio.password.set', ['token' => $this->token]).'?email='.urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('You have been invited to the studio')
            ->greeting("Hello {$notifiable->name},")
            ->line('You have been invited to join the content studio.')
            ->line('Set your password to activate your account and sign in.')
            ->action('Set your password', $url)
            ->line('This invitation link expires in 60 minutes. If it lapses, ask an administrator to resend it.');
    }
}

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Password-reset email for a public-portal learner — links to the portal SPA. */
class PortalResetPassword extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.url'), '/').'/reset-password?token='.$this->token
            .'&email='.urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Reset your Samchita password')
            ->greeting('Hello '.($notifiable->name ?? 'there'))
            ->line('We received a request to reset your password.')
            ->action('Reset password', $url)
            ->line('This link expires in 60 minutes. If you didn’t request it, you can ignore this email.');
    }
}

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Email-verification link for a portal learner. A signed, expiring URL to the
 * public verify route (no session required, so it works cross-device).
 */
class PortalVerifyEmail extends Notification
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('portal.verify', now()->addMinutes(60), [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ]);

        return (new MailMessage)
            ->subject('Verify your Samchita email')
            ->greeting('Hello '.($notifiable->name ?? 'there'))
            ->line('Confirm your email address to secure your account.')
            ->action('Verify email', $url)
            ->line('If you didn’t create a Samchita account, no action is needed.');
    }
}

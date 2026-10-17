<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $workspaceName,
        public readonly string $inviterName,
        public readonly string $acceptUrl,
        public readonly string $expiresAt,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Invitation to join ' . $this->workspaceName . ' on Semizzy')
            ->greeting('You have been invited to a Semizzy workspace')
            ->line($this->inviterName . ' invited you to join "' . $this->workspaceName . '".')
            ->line('Accept this invitation while signed in to the Semizzy account that received this email.')
            ->action('Accept workspace invitation', $this->acceptUrl)
            ->line('This invitation expires at ' . $this->expiresAt . '.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }
}

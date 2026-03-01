<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invitation $invitation) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspaceName = $this->invitation->workspace->name;

        return (new MailMessage)
            ->subject("You've been invited to join {$workspaceName}")
            ->greeting('Hello!')
            ->line("You have been invited to join the **{$workspaceName}** workspace as a **{$this->invitation->role}**.")
            ->line("This invitation will expire on {$this->invitation->expires_at->toFormattedDateString()}.")
            ->line('Please sign in to your account to accept or decline this invitation.');
    }
}

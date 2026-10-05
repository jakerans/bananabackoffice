<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

class StaffInvitation extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(#[SensitiveParameter] public string $token) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $hours = (int) (config('auth.passwords.invitations.expire') / 60);

        return (new MailMessage)
            ->subject(__('You have been invited to :app', ['app' => config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('An account has been created for you on :app. Choose your own password to finish setting it up.', ['app' => config('app.name')]))
            ->action(__('Set your password'), $this->acceptUrl($notifiable))
            ->line(__('This link can be used once and expires in :hours hours.', ['hours' => $hours]))
            ->line(__('If you were not expecting this invitation, you can ignore this email.'));
    }

    /**
     * Get the address where the staff member chooses their password.
     */
    public function acceptUrl(User $notifiable): string
    {
        return route('invitation.accept', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}

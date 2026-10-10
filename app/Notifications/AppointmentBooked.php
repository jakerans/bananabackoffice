<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentBooked extends Notification
{
    /** The notice inside the app: saved with the booking. */
    public const string IN_APP = 'database';

    /** The email: sent once the booking is saved. */
    public const string EMAIL = 'mail';

    /**
     * Tell a salesperson an appointment was booked for them (rulebook, rule 4).
     *
     * Neither the notice nor the email carries anything about the customer:
     * only when it is, and a link that the salesperson must be signed in and
     * allowed to open.
     *
     * @param  self::IN_APP|self::EMAIL  $channel
     */
    public function __construct(public Appointment $appointment, public string $channel) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [$this->channel];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You have a new appointment in :app', ['app' => config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('An appointment was booked for you on :when.', ['when' => $this->when()]))
            ->action(__('Open the appointment'), $this->url())
            ->line(__('Sign in to see who it is with and where. This email leaves those details out on purpose.'));
    }

    /**
     * What the bell in the header shows.
     *
     * @return array{kind: string, message: string, url: string, appointment_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'appointment_booked',
            'message' => __('A new appointment was booked for you on :when.', ['when' => $this->when()]),
            'url' => $this->url(),
            'appointment_id' => $this->appointment->id,
        ];
    }

    /**
     * When the appointment is, in the Location's own time, with its timezone.
     */
    private function when(): string
    {
        return $this->appointment->starts_at->copy()->timezone($this->appointment->timezone)->format('D, M j, Y \a\t g:i A T');
    }

    private function url(): string
    {
        return route('leads.show', $this->appointment->lead_id);
    }
}

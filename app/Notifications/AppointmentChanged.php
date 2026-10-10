<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentChanged extends Notification
{
    /** The notice inside the app: saved with the change. */
    public const string IN_APP = 'database';

    /** The email: sent once the change is saved. */
    public const string EMAIL = 'mail';

    /** Someone else gave the salesperson's appointment a new time. */
    public const string RESCHEDULED = 'appointment_rescheduled';

    /** A manager gave the salesperson an appointment that was someone else's. */
    public const string MOVED_TO_YOU = 'appointment_moved_to_you';

    /** A manager gave the salesperson's appointment to someone else. */
    public const string MOVED_AWAY = 'appointment_moved_away';

    /** Someone else recorded that the salesperson's appointment was cancelled. */
    public const string CANCELLED = 'appointment_cancelled';

    /** The salesperson's Lead went back to Contact Center. */
    public const string LEAD_RETURNED = 'lead_returned_from_sales';

    /**
     * Tell a salesperson that an appointment of theirs changed, or that a Lead
     * they had went back to Contact Center.
     *
     * Like the booking notice, nothing here says anything about the customer:
     * only what happened, when the appointment is, and a link the salesperson
     * must be signed in and allowed to open.
     *
     * @param  self::RESCHEDULED|self::MOVED_TO_YOU|self::MOVED_AWAY|self::CANCELLED|self::LEAD_RETURNED  $kind
     * @param  self::IN_APP|self::EMAIL  $channel
     * @param  Appointment|null  $appointment  The appointment as it is after the change, when there is one.
     */
    public function __construct(public int $leadId, public string $kind, public string $channel, public ?Appointment $appointment = null) {}

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
     * Get the mail representation of the notification. Only a new time and a
     * newly given appointment are emailed.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->kind === self::MOVED_TO_YOU
                ? __('You have a new appointment in :app', ['app' => config('app.name')])
                : __('An appointment of yours has a new time in :app', ['app' => config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line($this->message())
            ->action(__('Open the appointment'), $this->url())
            ->line(__('Sign in to see who it is with and where. This email leaves those details out on purpose.'));
    }

    /**
     * What the bell in the header shows.
     *
     * @return array{kind: string, message: string, url: string, appointment_id: int|null}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'message' => $this->message(),
            'url' => $this->url(),
            'appointment_id' => $this->appointment?->id,
        ];
    }

    private function message(): string
    {
        return match ($this->kind) {
            self::RESCHEDULED => __('An appointment of yours was moved to :when.', ['when' => $this->when()]),
            self::MOVED_TO_YOU => __('An appointment on :when was given to you.', ['when' => $this->when()]),
            self::MOVED_AWAY => __('Your appointment on :when was given to someone else.', ['when' => $this->when()]),
            self::CANCELLED => __('Your appointment on :when was cancelled.', ['when' => $this->when()]),
            self::LEAD_RETURNED => __('A lead you had went back to the Contact Center.'),
        };
    }

    /**
     * When the appointment is, in the Location's own time, with its timezone.
     */
    private function when(): string
    {
        return $this->appointment === null
            ? ''
            : $this->appointment->starts_at->copy()->timezone($this->appointment->timezone)->format('D, M j, Y \a\t g:i A T');
    }

    private function url(): string
    {
        return route('leads.show', $this->leadId);
    }
}

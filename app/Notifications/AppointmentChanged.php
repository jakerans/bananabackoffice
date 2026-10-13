<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Models\Lead;
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

    /** The salesperson's cancelled Lead was not booked again in time and went back by itself. */
    public const string LEAD_RELEASED = 'lead_released_from_sales';

    /** The salesperson's cancelled Lead will go back soon unless it is booked again. */
    public const string RELEASE_WARNING = 'lead_release_warning';

    /** The salesperson's appointment is over and nobody has said what happened at it. */
    public const string CLOSE_OUT_REMINDER = 'appointment_close_out_reminder';

    /** A manager said the salesperson's appointment was run. */
    public const string MARKED_RUN = 'appointment_marked_run';

    /** A manager said the salesperson's appointment was a no-show. */
    public const string MARKED_NO_SHOW = 'appointment_marked_no_show';

    /** A manager corrected what the salesperson's appointment was marked as. */
    public const string CLOSE_OUT_CORRECTED = 'appointment_close_out_corrected';

    /** A manager marked the salesperson's appointment "booked in error". */
    public const string BOOKED_IN_ERROR = 'appointment_booked_in_error';

    /** Someone else recorded a proposal at the salesperson's appointment. */
    public const string PROPOSAL_RECORDED = 'appointment_proposal_recorded';

    /** A manager changed a proposal, a result or the lost mark on a Lead the salesperson has. */
    public const string SALES_RECORD_CHANGED = 'lead_sales_record_changed';

    /**
     * Tell a salesperson that an appointment of theirs changed, or that a Lead
     * they had went back to Contact Center.
     *
     * Like the booking notice, nothing here says anything about the customer:
     * only what happened, when the appointment is, and a link the salesperson
     * must be signed in and allowed to open.
     *
     * @param  self::RESCHEDULED|self::MOVED_TO_YOU|self::MOVED_AWAY|self::CANCELLED|self::LEAD_RETURNED|self::LEAD_RELEASED|self::RELEASE_WARNING|self::CLOSE_OUT_REMINDER|self::MARKED_RUN|self::MARKED_NO_SHOW|self::CLOSE_OUT_CORRECTED|self::BOOKED_IN_ERROR|self::PROPOSAL_RECORDED|self::SALES_RECORD_CHANGED  $kind
     * @param  self::IN_APP|self::EMAIL  $channel
     * @param  Appointment|null  $appointment  The appointment as it is after the change, when there is one.
     * @param  int|null  $days  For a release warning: how many days are left.
     */
    public function __construct(public int $leadId, public string $kind, public string $channel, public ?Appointment $appointment = null, public ?int $days = null) {}

    /**
     * Tell the salesperson a Lead is with, inside the app, that someone else
     * changed its proposals, a result or its lost mark (decision 126). Nobody
     * is told about what they did themselves.
     */
    public static function tellSalespersonOfSalesChange(Lead $lead, User $actor): void
    {
        if ($lead->assigned_user_id === null || $lead->assigned_user_id === $actor->id) {
            return;
        }

        User::query()->find($lead->assigned_user_id)?->notify(new self($lead->id, self::SALES_RECORD_CHANGED, self::IN_APP));
    }

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
     * Get the mail representation of the notification. Only a new time, a
     * newly given appointment and the reminder to close one out are emailed.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(match ($this->kind) {
                self::MOVED_TO_YOU => __('You have a new appointment in :app', ['app' => config('app.name')]),
                self::CLOSE_OUT_REMINDER => __('Close out your appointment in :app', ['app' => config('app.name')]),
                default => __('An appointment of yours has a new time in :app', ['app' => config('app.name')]),
            })
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line($this->message())
            ->action($this->kind === self::CLOSE_OUT_REMINDER ? __('Close it out') : __('Open the appointment'), $this->url())
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
            self::CLOSE_OUT_REMINDER => __('Your appointment on :when is over. Say whether it was run or was a no-show. Nothing else can be done with the lead until you do.', ['when' => $this->when()]),
            self::MARKED_RUN => __('A manager marked your appointment on :when as run.', ['when' => $this->when()]),
            self::MARKED_NO_SHOW => __('A manager marked your appointment on :when as a no-show.', ['when' => $this->when()]),
            self::CLOSE_OUT_CORRECTED => __('A manager corrected what happened at your appointment on :when.', ['when' => $this->when()]),
            self::BOOKED_IN_ERROR => __('A manager marked your appointment on :when as booked in error, and the lead went back as it was.', ['when' => $this->when()]),
            self::PROPOSAL_RECORDED => __('A manager recorded a proposal at your appointment on :when.', ['when' => $this->when()]),
            self::SALES_RECORD_CHANGED => __('A manager changed the proposals or the result on a lead of yours.'),
            self::LEAD_RELEASED => __('A cancelled lead of yours was not booked again in time and went back to the Contact Center. You can take it back from the lead.'),
            self::RELEASE_WARNING => trans_choice('{1} A cancelled lead of yours goes back to the Contact Center in 1 day unless it is booked again.|[2,*] A cancelled lead of yours goes back to the Contact Center in :count days unless it is booked again.', (int) $this->days, ['count' => (int) $this->days]),
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

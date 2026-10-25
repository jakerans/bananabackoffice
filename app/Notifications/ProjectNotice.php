<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectNotice extends Notification
{
    /** The notice kept inside Backoffice, shown by the bell. */
    public const string IN_APP = 'database';

    /** The email. */
    public const string EMAIL = 'mail';

    /** The salesperson has a sold job whose handoff questions are not answered. */
    public const string HANDOFF_REMINDER = 'project_handoff_reminder';

    /** A sold job's handoff is complete: Production can take it. */
    public const string READY_FOR_PRODUCTION = 'project_ready_for_production';

    /** A manager made the person a Project's Production Manager. */
    public const string ASSIGNED_TO_YOU = 'project_assigned_to_you';

    /** A Project the person had was given to another Production Manager. */
    public const string GIVEN_TO_ANOTHER = 'project_given_to_another';

    /** A job the salesperson sold now has a Production Manager. */
    public const string HAS_PRODUCTION_MANAGER = 'project_has_production_manager';

    /** The handoff of a Project the Production Manager has was changed. */
    public const string HANDOFF_CHANGED = 'project_handoff_changed';

    /** A Project the Production Manager has was cancelled because its sale was corrected. */
    public const string CANCELLED = 'project_cancelled';

    /** A job the salesperson sold has dates the customer confirmed (slice 11, step 11b). */
    public const string SCHEDULED = 'project_scheduled';

    /** The confirmed dates of a job the salesperson sold were changed. */
    public const string RESCHEDULED = 'project_rescheduled';

    /** The deposit of the Production Manager's job is paid: call the customer within 24 hours (slice 17, step 17b). */
    public const string DEPOSIT_PAID = 'project_deposit_paid';

    /** A Production Manager has a third job-costing accuracy strike in 90 days (slice 19, step 19b; decision 133). */
    public const string ACCURACY_STRIKES = 'project_accuracy_strikes';

    /** A job at the manager's Location was sold with a custom Brand Ambassador package (slice 21, step 21a). */
    public const string AMBASSADOR_CUSTOM = 'project_ambassador_custom';

    /**
     * Tell someone about a Project: that its handoff is waiting or done, or
     * that it has changed hands (docs/slice-8-projects-plan.md, step 8b;
     * product-architecture.md, 20: every ownership transfer notifies the new
     * owner).
     *
     * Nothing here says anything about the customer, and the Project's title
     * is left out because it carries the street address. It says what
     * happened and links to the Project, which the person must be signed in
     * and allowed to open.
     *
     * @param  self::HANDOFF_REMINDER|self::READY_FOR_PRODUCTION|self::ASSIGNED_TO_YOU|self::GIVEN_TO_ANOTHER|self::HAS_PRODUCTION_MANAGER|self::HANDOFF_CHANGED|self::CANCELLED|self::SCHEDULED|self::RESCHEDULED|self::DEPOSIT_PAID|self::ACCURACY_STRIKES|self::AMBASSADOR_CUSTOM  $kind
     * @param  self::IN_APP|self::EMAIL  $channel
     * @param  string|null  $name  A staff member, a Location or the days of the work to name, where the message has one.
     */
    public function __construct(public int $projectId, public string $kind, public string $channel = self::IN_APP, public ?string $name = null) {}

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
     * Get the mail representation of the notification. Only the reminder to
     * complete a handoff is emailed.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('A job you sold needs its handoff in :app', ['app' => config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line($this->message())
            ->action(__('Complete the handoff'), $this->url())
            ->line(__('Sign in to see which job it is. This email leaves those details out on purpose.'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array{kind: string, message: string, url: string, project_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'message' => $this->message(),
            'url' => $this->url(),
            'project_id' => $this->projectId,
        ];
    }

    private function message(): string
    {
        return match ($this->kind) {
            self::HANDOFF_REMINDER => __('A job you sold still needs its handoff. Production cannot take it until you answer the handoff questions.'),
            self::READY_FOR_PRODUCTION => __('A sold job at :location is ready for Production.', ['location' => (string) $this->name]),
            self::ASSIGNED_TO_YOU => __('You were made the Production Manager of a project.'),
            self::GIVEN_TO_ANOTHER => __('A project you had was given to another Production Manager.'),
            self::HAS_PRODUCTION_MANAGER => __(':name is now the Production Manager for a job you sold.', ['name' => (string) $this->name]),
            self::HANDOFF_CHANGED => __('The handoff answers for a project of yours were changed.'),
            self::CANCELLED => __('A project of yours was cancelled because its sale was corrected.'),
            self::SCHEDULED => __('A job you sold is scheduled: :days. The customer confirmed the dates.', ['days' => (string) $this->name]),
            self::RESCHEDULED => __('A job you sold was rescheduled: it is now :days.', ['days' => (string) $this->name]),
            self::DEPOSIT_PAID => __('The deposit for a job of yours is paid. Call the customer within 24 hours.'),
            self::ACCURACY_STRIKES => __(':name has a third job-costing accuracy strike in 90 days.', ['name' => (string) $this->name]),
            self::AMBASSADOR_CUSTOM => __(':name recorded a custom Brand Ambassador package on a job.', ['name' => (string) $this->name]),
        };
    }

    private function url(): string
    {
        return $this->kind === self::HANDOFF_REMINDER ? route('projects.handoff', $this->projectId) : route('projects.show', $this->projectId);
    }
}

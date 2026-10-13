<?php

namespace App\Enums;

/**
 * Where a Lead is in its own lifecycle.
 *
 * This is never a project status, and Disqualified is never a lost sale: a Lead
 * is disqualified, a Project is lost.
 */
enum LeadStatus: string
{
    /** Received; nobody has worked it yet. */
    case New = 'new';

    /** Someone is in contact or trying to be. */
    case Working = 'working';

    /** The qualification milestone only; not booked, issued, demoed, sold or Project-created. */
    case Qualified = 'qualified';

    /** An appointment is saved with a salesperson: the Lead is issued to Sales. */
    case AppointmentBooked = 'appointment_booked';

    /** Its appointment was cancelled or was a no-show; it is still with the salesperson, to be booked again. */
    case AppointmentCancelled = 'appointment_cancelled';

    /** Its appointment was run; it is with the salesperson, who is selling (decision 125). */
    case AppointmentRun = 'appointment_run';

    /** One of its proposals was sold (decision 125). Finished for Sales; it stays on its salesperson's record. */
    case Sold = 'sold';

    /** The salesperson or a manager marked it lost, with a reason (decision 125). Finished. */
    case Lost = 'lost';

    /** A deliberate manual callback. The stored value is retained for compatibility. */
    case Nurture = 'nurture';

    /** Did not become a legitimate opportunity, for a recorded reason. */
    case Disqualified = 'disqualified';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Working => 'Working',
            self::Qualified => 'Qualified',
            self::AppointmentBooked => 'Appointment booked',
            self::AppointmentCancelled => 'Appointment cancelled',
            self::AppointmentRun => 'Appointment run',
            self::Sold => 'Sold',
            self::Lost => 'Lost',
            self::Nurture => 'Deferred Follow-up',
            self::Disqualified => 'Disqualified',
        };
    }

    /**
     * Whether a Lead in this status still needs someone's attention.
     *
     * Everything does but Disqualified, Sold and Lost, which are finished. Qualified records remain
     * active; this milestone alone does not create or imply any downstream workflow.
     */
    public function isActive(): bool
    {
        return $this !== self::Disqualified && ! $this->isFinishedBySales();
    }

    /**
     * Whether Sales has finished with a Lead in this status: it was sold, or
     * marked lost. It is no longer "with Sales" to be booked or sent back, and
     * only a manager's correction changes it.
     */
    public function isFinishedBySales(): bool
    {
        return $this === self::Sold || $this === self::Lost;
    }

    /**
     * Whether a Lead in this status has been issued and is with Sales: its
     * appointment stands, has passed, was run, or fell through and waits to be
     * booked again. Leaving this is "going back to Contact Center".
     */
    public function isWithSales(): bool
    {
        return $this === self::AppointmentBooked || $this === self::AppointmentCancelled || $this === self::AppointmentRun;
    }

    /**
     * The statuses of Leads that still need someone's attention.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isActive()));
    }
}

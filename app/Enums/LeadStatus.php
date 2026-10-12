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
            self::Nurture => 'Deferred Follow-up',
            self::Disqualified => 'Disqualified',
        };
    }

    /**
     * Whether a Lead in this status still needs someone's attention.
     *
     * Everything but Disqualified does. Qualified records remain active; this milestone alone does not
     * create or imply any downstream workflow.
     */
    public function isActive(): bool
    {
        return $this !== self::Disqualified;
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

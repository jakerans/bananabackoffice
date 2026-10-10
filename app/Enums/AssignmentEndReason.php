<?php

namespace App\Enums;

/**
 * Why someone stopped being the person responsible for a Lead.
 */
enum AssignmentEndReason: string
{
    /** The Lead was given to someone else. */
    case Reassigned = 'reassigned';

    /** The Lead was deliberately left with nobody assigned. */
    case Released = 'released';

    /** The Lead moved to another location, or back to central: a handoff. */
    case LocationChanged = 'location_changed';

    /** The person can no longer work Leads there, or their account was deactivated. */
    case AccessRemoved = 'access_removed';

    /** The Lead was disqualified, which ends anyone's responsibility for it. */
    case LeadDisqualified = 'lead_disqualified';

    /** The appointment that gave the salesperson the Lead was booked in error. */
    case AppointmentUndone = 'appointment_undone';

    /** The Lead went back from Sales to Contact Center. */
    case ReturnedFromSales = 'returned_from_sales';

    public function label(): string
    {
        return match ($this) {
            self::Reassigned => 'Reassigned',
            self::Released => 'Released',
            self::LocationChanged => 'The lead changed location',
            self::AccessRemoved => 'Access was removed',
            self::LeadDisqualified => 'The lead was disqualified',
            self::AppointmentUndone => 'The appointment was booked in error',
            self::ReturnedFromSales => 'The lead went back to Contact Center',
        };
    }
}

<?php

namespace App\Enums;

/**
 * Why an Owner stopped viewing Backoffice as another staff member.
 */
enum ImpersonationEndReason: string
{
    /** The Owner pressed "Return to ...". */
    case Returned = 'returned';

    /** The Owner signed out, which ends the whole session. */
    case SignedOut = 'signed_out';

    /** The staff member was deactivated, or can no longer be viewed as. */
    case StaffMemberUnavailable = 'staff_member_unavailable';

    /** The person who started it is no longer an Owner who may do this. */
    case OwnerNoLongerAuthorized = 'owner_no_longer_authorized';
}

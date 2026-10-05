<?php

namespace App\Enums;

/**
 * The important changes Backoffice keeps a permanent record of.
 *
 * A stored value never changes once events exist that use it. Add a case in
 * the step that builds the change it records.
 */
enum AuditAction: string
{
    case StaffInvited = 'staff.invited';
    case StaffInvitationResent = 'staff.invitation_resent';
    case StaffInvitationAccepted = 'staff.invitation_accepted';
    case StaffAccessGranted = 'staff.access_granted';
    case StaffAccessRevoked = 'staff.access_revoked';
    case StaffDeactivated = 'staff.deactivated';
    case StaffReactivated = 'staff.reactivated';
    case LocationCreated = 'location.created';
    case LocationRenamed = 'location.renamed';
    case LocationDeactivated = 'location.deactivated';
    case LocationReactivated = 'location.reactivated';
    case ListOptionAdded = 'list_option.added';
    case ListOptionRenamed = 'list_option.renamed';
    case ListOptionReclassified = 'list_option.reclassified';
    case ListOptionMoved = 'list_option.moved';
    case ListOptionDeactivated = 'list_option.deactivated';
    case ListOptionReactivated = 'list_option.reactivated';
}

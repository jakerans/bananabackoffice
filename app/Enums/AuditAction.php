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
    case StaffImpersonationStarted = 'staff.impersonation_started';
    case StaffImpersonationStopped = 'staff.impersonation_stopped';
    case LocationCreated = 'location.created';
    case LocationRenamed = 'location.renamed';
    case LocationDeactivated = 'location.deactivated';
    case LocationReactivated = 'location.reactivated';
    case LocationServiceAreaChanged = 'location.service_area_changed';
    case ListOptionAdded = 'list_option.added';
    case ListOptionRenamed = 'list_option.renamed';
    case ListOptionReclassified = 'list_option.reclassified';
    case ListOptionMoved = 'list_option.moved';
    case ListOptionDeactivated = 'list_option.deactivated';
    case ListOptionReactivated = 'list_option.reactivated';
    case LeadReceived = 'lead.received';
    case LeadInquiryCorrected = 'lead.inquiry_corrected';
    case LeadAttributionChanged = 'lead.attribution_changed';
    case LeadWorkStarted = 'lead.work_started';
    case LeadServicesChanged = 'lead.services_changed';
    case LeadProjectSizeChanged = 'lead.project_size_changed';
    case LeadRouted = 'lead.routed';
    case LeadTransferred = 'lead.transferred';
    case LeadReturnedToCentral = 'lead.returned_to_central';
    case LeadAssigned = 'lead.assigned';
    case LeadReassigned = 'lead.reassigned';
    case LeadUnassigned = 'lead.unassigned';
    case LeadReceivedTimeCorrected = 'lead.received_time_corrected';
    case AccountCreated = 'account.created';
    case ContactCreated = 'contact.created';
    case ContactDetailsEdited = 'contact.details_edited';
    case ContactConnected = 'account.contact_connected';
    case ContactDisconnected = 'account.contact_disconnected';
    case ContactReconnected = 'account.contact_reconnected';
    case ContactRolesChanged = 'account.contact_roles_changed';
    case PrimaryContactChanged = 'account.primary_contact_changed';
    case PropertyCreated = 'property.created';
    case PropertyEdited = 'property.edited';
    case PropertyArchived = 'property.archived';
    case PropertyRestored = 'property.restored';
    case NoteAddedWhileImpersonating = 'note.added_while_impersonating';
    case NoteRemoved = 'note.removed';
    case ProjectSizeRangeChanged = 'project_size.range_changed';
}

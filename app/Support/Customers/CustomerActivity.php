<?php

namespace App\Support\Customers;

use App\Enums\AuditAction;

class CustomerActivity
{
    /**
     * The existing audit events that are meaningful in a customer-facing business timeline.
     *
     * Field corrections, list maintenance, note operations and customer/contact/property
     * administration deliberately stay in the audit record without becoming customer activity.
     *
     * @return list<AuditAction>
     */
    public static function actions(): array
    {
        return [
            AuditAction::AccountCreated,
            AuditAction::AccountArchived,
            AuditAction::AccountRestored,
            AuditAction::LeadReceived,
            AuditAction::LeadAttributionChanged,
            AuditAction::LeadWorkStarted,
            AuditAction::LeadRouted,
            AuditAction::LeadTransferred,
            AuditAction::LeadReturnedToCentral,
            AuditAction::LeadAssigned,
            AuditAction::LeadReassigned,
            AuditAction::LeadUnassigned,
            AuditAction::LeadQualified,
            AuditAction::LeadReturnedToWorking,
            AuditAction::LeadMovedToNurture,
            AuditAction::LeadFollowUpRescheduled,
            AuditAction::LeadResumedFromNurture,
            AuditAction::LeadDisqualified,
            AuditAction::LeadReopened,
            AuditAction::LeadAppointmentBooked,
            AuditAction::LeadAppointmentBookedInError,
            AuditAction::LeadCustomerLinked,
            AuditAction::LeadCustomerLinkChanged,
        ];
    }

    public static function label(AuditAction $action): string
    {
        return match ($action) {
            AuditAction::AccountCreated => __('Customer created'),
            AuditAction::AccountArchived => __('Customer archived'),
            AuditAction::AccountRestored => __('Customer restored'),
            AuditAction::LeadReceived => __('Lead received'),
            AuditAction::LeadAttributionChanged => __('Lead source changed'),
            AuditAction::LeadWorkStarted => __('Work started'),
            AuditAction::LeadRouted => __('Lead routed'),
            AuditAction::LeadTransferred => __('Lead transferred'),
            AuditAction::LeadReturnedToCentral => __('Lead returned to central'),
            AuditAction::LeadAssigned => __('Lead assigned'),
            AuditAction::LeadReassigned => __('Lead reassigned'),
            AuditAction::LeadUnassigned => __('Lead unassigned'),
            AuditAction::LeadQualified => __('Lead qualified'),
            AuditAction::LeadReturnedToWorking => __('Lead returned to working'),
            AuditAction::LeadMovedToNurture => __('Lead moved to nurture'),
            AuditAction::LeadFollowUpRescheduled => __('Follow-up rescheduled'),
            AuditAction::LeadResumedFromNurture => __('Lead resumed from nurture'),
            AuditAction::LeadDisqualified => __('Lead disqualified'),
            AuditAction::LeadReopened => __('Lead reopened'),
            AuditAction::LeadAppointmentBooked => __('Appointment booked'),
            AuditAction::LeadAppointmentBookedInError => __('Appointment booked in error'),
            AuditAction::LeadCustomerLinked => __('Lead linked to customer'),
            AuditAction::LeadCustomerLinkChanged => __('Lead customer link changed'),
            default => throw new \LogicException('This audit action is not customer activity.'),
        };
    }
}

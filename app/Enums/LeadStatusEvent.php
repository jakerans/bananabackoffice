<?php

namespace App\Enums;

/**
 * What happened to a Lead's lifecycle at one moment in its history.
 *
 * Most are a change of status. Two are not: a follow-up being rescheduled and a
 * disqualification reason being corrected leave the status where it was, and
 * are still part of the story of the Lead.
 */
enum LeadStatusEvent: string
{
    case StartedWorking = 'started_working';
    case Qualified = 'qualified';
    case ReturnedToWorking = 'returned_to_working';
    case MovedToNurture = 'moved_to_nurture';
    case FollowUpRescheduled = 'follow_up_rescheduled';
    case ResumedWorking = 'resumed_working';
    case Disqualified = 'disqualified';
    case DisqualificationCorrected = 'disqualification_corrected';
    case Reopened = 'reopened';
    case AppointmentBooked = 'appointment_booked';
    case AppointmentBookedInError = 'appointment_booked_in_error';
    case AppointmentCancelled = 'appointment_cancelled';
    case AppointmentRebooked = 'appointment_rebooked';
    case ReturnedFromSales = 'returned_from_sales';
    case TakenBackBySales = 'taken_back_by_sales';
    case AppointmentRun = 'appointment_run';
    case AppointmentNoShow = 'appointment_no_show';
    case AppointmentCloseOutCorrected = 'appointment_close_out_corrected';
}

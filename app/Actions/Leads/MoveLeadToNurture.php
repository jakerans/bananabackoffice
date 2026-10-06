<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MoveLeadToNurture
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private RecordLeadStatusChange $recordLeadStatusChange,
    ) {}

    /**
     * Move a Lead to Nurture: still a viable opportunity, not ready now, to be
     * followed up on a set date.
     *
     * It needs someone assigned, who owns the follow-up; a date, today or later;
     * and a short note saying what to follow up about. Nothing happens by itself
     * when the date arrives: the Lead is shown as due to the person who has it.
     *
     * A Lead already in Nurture has its follow-up changed instead: a new date
     * and note, with the status left as it is.
     *
     * Nurture is never a disqualification and never a lost sale.
     *
     * The caller is responsible for checking that the person may do this.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, ?string $followUpOn, ?string $note, User $actor): void
    {
        $rescheduling = $lead->status === LeadStatus::Nurture;

        if (! $rescheduling && ! in_array($lead->status, [LeadStatus::Working, LeadStatus::Qualified], true)) {
            throw ValidationException::withMessages([
                'status' => __('Only a lead that is being worked, or is qualified, can be moved to nurture. This one is :status.', ['status' => $lead->status->label()]),
            ]);
        }

        if (! $lead->isAssigned()) {
            throw ValidationException::withMessages([
                'followUpOn' => __('Someone must be assigned to this lead to own the follow-up.'),
            ]);
        }

        $date = self::followUpDate($followUpOn);
        $note = trim((string) $note);

        if ($note === '') {
            throw ValidationException::withMessages([
                'followUpNote' => __('Say what to follow up about.'),
            ]);
        }

        if ($rescheduling && $lead->follow_up_on?->toDateString() === $date->toDateString() && $lead->follow_up_note === $note) {
            return;
        }

        DB::transaction(function () use ($lead, $date, $note, $actor, $rescheduling): void {
            $from = $lead->status;
            $before = ['status' => $from->value, 'follow_up_on' => $lead->follow_up_on?->toDateString()];

            $lead->forceFill([
                'status' => LeadStatus::Nurture,
                'status_changed_at' => $rescheduling ? $lead->status_changed_at : now(),
                'follow_up_on' => $date->toDateString(),
                'follow_up_note' => $note,
            ])->save();

            $this->recordLeadStatusChange->handle(
                $lead,
                $rescheduling ? LeadStatusEvent::FollowUpRescheduled : LeadStatusEvent::MovedToNurture,
                $from,
                $actor,
                $lead->assigned_user_id,
                followUpOn: $date,
                note: $note,
            );

            $this->recordAuditEvent->handle(
                $rescheduling ? AuditAction::LeadFollowUpRescheduled : AuditAction::LeadMovedToNurture,
                $lead,
                actor: $actor,
                before: $before,
                after: ['status' => LeadStatus::Nurture->value, 'follow_up_on' => $date->toDateString()],
                location: $lead->location,
                reason: $note,
            );
        });
    }

    /**
     * A follow-up date is a calendar day where the company is: today or later.
     *
     * @throws ValidationException
     */
    private static function followUpDate(?string $typed): CarbonInterface
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', trim((string) $typed)) ?: null;

        if ($date === null || $date->format('Y-m-d') !== trim((string) $typed)) {
            throw ValidationException::withMessages([
                'followUpOn' => __('Choose the date to follow up.'),
            ]);
        }

        if ($date->toDateString() < Lead::today()) {
            throw ValidationException::withMessages([
                'followUpOn' => __('The follow-up date cannot be in the past.'),
            ]);
        }

        return $date;
    }
}

<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Models\Lead;
use App\Models\LeadDisqualificationReason;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CorrectLeadDisqualification
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordAuditEvent $recordAuditEvent,
        private RecordLeadStatusChange $recordLeadStatusChange,
    ) {}

    /**
     * Correct why a Lead was disqualified, while it still is: the reason, its
     * explanation, or the Lead it repeats.
     *
     * The Lead then shows the corrected reason. The original entry in its
     * history is left exactly as it was written, and a new entry records the
     * correction, so what was said first and what it was changed to can both be
     * seen. A new reason brings the category it has now.
     *
     * The same rules apply as when disqualifying. Saving it unchanged does
     * nothing and records nothing.
     *
     * The caller is responsible for checking that the person may do this.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, LeadDisqualificationReason $reason, ?string $note, User $actor, ?Lead $duplicateOf = null, ?int $expectedVersion = null): void
    {
        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $reason, $note, $actor, $duplicateOf): bool {
            $reason->refresh();

            if ($lead->status !== LeadStatus::Disqualified) {
                throw ValidationException::withMessages([
                    'status' => __('Only a disqualified lead has a reason to correct.'),
                ]);
            }

            $reasonChanged = $lead->disqualification_reason_id !== $reason->id;

            if ($reasonChanged && ! $reason->is_active) {
                throw ValidationException::withMessages([
                    'disqualificationReasonId' => __(':reason is no longer offered. Choose another.', ['reason' => $reason->name]),
                ]);
            }

            $note = DisqualifyLead::clean($note);
            $duplicateOf = $reason->isDuplicate() ? $duplicateOf : null;

            DisqualifyLead::ensureExplained($reason, $note, $duplicateOf);
            DisqualifyLead::ensureOriginalIsUsable($lead, $duplicateOf);

            if ($reasonChanged && $reason->isUnableToContact()) {
                throw ValidationException::withMessages([
                    'disqualificationReasonId' => __('Unable to Contact is historical only. Choose another reason.'),
                ]);
            }

            if (! $reasonChanged && $lead->disqualification_note === $note && $lead->duplicate_of_lead_id === $duplicateOf?->id) {
                return false;
            }

            $before = [
                'reason' => $lead->disqualificationReason?->name,
                'category' => $lead->disqualification_category?->value,
                'note' => $lead->disqualification_note,
                'duplicate_of_lead_id' => $lead->duplicate_of_lead_id,
            ];

            // An unchanged reason keeps the category it had when the Lead was disqualified.
            $category = $reasonChanged ? $reason->category : ($lead->disqualification_category ?? $reason->category);

            $lead->forceFill([
                'disqualification_reason_id' => $reason->id,
                'disqualification_category' => $category,
                'disqualification_note' => $note,
                'duplicate_of_lead_id' => $duplicateOf?->id,
            ])->save();

            $lead->setRelation('disqualificationReason', $reason);
            $lead->setRelation('duplicateOf', $duplicateOf);

            $this->recordLeadStatusChange->handle(
                $lead,
                LeadStatusEvent::DisqualificationCorrected,
                LeadStatus::Disqualified,
                $actor,
                reason: $reason,
                category: $category,
                duplicateOf: $duplicateOf,
                note: $note,
            );

            $this->recordAuditEvent->handle(
                AuditAction::LeadDisqualificationCorrected,
                $lead,
                actor: $actor,
                before: $before,
                after: [
                    'reason' => $reason->name,
                    'category' => $category->value,
                    'note' => $note,
                    'duplicate_of_lead_id' => $duplicateOf?->id,
                ],
                location: $lead->location,
            );

            return true;
        });
    }
}

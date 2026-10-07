<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AssignmentEndReason;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Models\Lead;
use App\Models\LeadDisqualificationReason;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class DisqualifyLead
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordAuditEvent $recordAuditEvent,
        private RecordLeadStatusChange $recordLeadStatusChange,
        private UnassignLead $unassignLead,
    ) {}

    /**
     * Disqualify a Lead: it did not become a legitimate opportunity, for a
     * recorded reason. This is never a lost sale; that is recorded on a Project.
     *
     * It ends anyone's responsibility for the Lead, so the current assignment is
     * closed (and kept in the assignment history) and the Lead is left with
     * nobody assigned.
     *
     * Everything else about the Lead is kept exactly as it is: the inquiry, its
     * attribution, its services and size, and its links to a customer.
     *
     * The reason's reporting category is copied onto the Lead and its history as
     * it stands now, so recategorizing the reason later changes nothing that
     * already happened.
     *
     * The caller is responsible for checking that the person may do this, that
     * they may see the original Lead they name.
     *
     * @param  Lead|null  $duplicateOf  The Lead this one repeats, for the "duplicate" reason.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, LeadDisqualificationReason $reason, ?string $note, User $actor, ?Lead $duplicateOf = null, ?int $expectedVersion = null): void
    {
        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $reason, $note, $actor, $duplicateOf): bool {
            $reason->refresh();

            if (! $lead->isActive()) {
                throw ValidationException::withMessages([
                    'status' => __('This lead is already disqualified.'),
                ]);
            }

            if (! $reason->is_active) {
                throw ValidationException::withMessages([
                    'disqualificationReasonId' => __(':reason is no longer offered. Choose another.', ['reason' => $reason->name]),
                ]);
            }

            $note = self::clean($note);
            $duplicateOf = $reason->isDuplicate() ? $duplicateOf : null;

            self::ensureExplained($reason, $note, $duplicateOf);
            self::ensureOriginalIsUsable($lead, $duplicateOf);

            if ($reason->isUnableToContact()) {
                throw ValidationException::withMessages([
                    'disqualificationReasonId' => __('Unable to Contact is historical only. Nonresponse is not a reason to disqualify a lead. Schedule a follow-up instead.'),
                ]);
            }

            $from = $lead->status;
            $assigneeId = $lead->assigned_user_id;
            $assignee = $lead->assignee;
            $before = array_filter(['status' => $from->value, 'follow_up_on' => $lead->follow_up_on?->toDateString(), 'assignee' => $assignee?->name]);

            $this->unassignLead->handle($lead, AssignmentEndReason::LeadDisqualified, $actor);

            $lead->forceFill([
                'status' => LeadStatus::Disqualified,
                'status_changed_at' => now(),
                'last_disqualified_at' => now(),
                'disqualified_by' => $actor->id,
                'disqualification_reason_id' => $reason->id,
                'disqualification_category' => $reason->category,
                'disqualification_note' => $note,
                'duplicate_of_lead_id' => $duplicateOf?->id,
                'follow_up_on' => null,
                'follow_up_note' => null,
            ])->save();

            $lead->setRelation('disqualificationReason', $reason);
            $lead->setRelation('duplicateOf', $duplicateOf);

            $this->recordLeadStatusChange->handle(
                $lead,
                LeadStatusEvent::Disqualified,
                $from,
                $actor,
                $assigneeId,
                reason: $reason,
                duplicateOf: $duplicateOf,
                note: $note,
            );

            $this->recordAuditEvent->handle(
                AuditAction::LeadDisqualified,
                $lead,
                actor: $actor,
                before: $before,
                after: [
                    'status' => LeadStatus::Disqualified->value,
                    'reason' => $reason->name,
                    'category' => $reason->category->value,
                    'duplicate_of_lead_id' => $duplicateOf?->id,
                ],
                location: $lead->location,
                reason: $note,
            );

            return true;
        });
    }

    /**
     * Other Leads the viewer can see that this one may be a repeat of: the same
     * phone, the same email, or the same customer.
     *
     * Only Leads the viewer may already see are ever offered, and never one that
     * is itself recorded as a duplicate.
     *
     * @return Collection<int, Lead>
     */
    public static function possibleOriginals(Lead $lead, User $viewer): Collection
    {
        $phone = $lead->inquiry_phone_normalized;
        $email = $lead->inquiry_email_normalized;
        $accountId = $lead->account_id;

        if ($phone === null && $email === null && $accountId === null) {
            return new Collection;
        }

        return Lead::query()
            ->visibleTo($viewer)
            ->whereKeyNot($lead->id)
            ->whereNull('duplicate_of_lead_id')
            ->where(function (Builder $query) use ($phone, $email, $accountId): void {
                if ($phone !== null) {
                    $query->orWhere('inquiry_phone_normalized', $phone);
                }

                if ($email !== null) {
                    $query->orWhere('inquiry_email_normalized', $email);
                }

                if ($accountId !== null) {
                    $query->orWhere('account_id', $accountId);
                }
            })
            ->with(['location', 'services'])
            ->orderByDesc('received_at')
            ->limit(8)
            ->get();
    }

    /**
     * A reason that needs explaining must be explained. For a duplicate, naming
     * the original is the explanation; without it, the person must say why the
     * original cannot be identified or opened.
     *
     * @throws ValidationException
     */
    public static function ensureExplained(LeadDisqualificationReason $reason, ?string $note, ?Lead $duplicateOf): void
    {
        if ($reason->isDuplicate() && $duplicateOf === null && $note === null) {
            throw ValidationException::withMessages([
                'duplicateOfId' => __('Choose the lead this one repeats. If it cannot be identified or you cannot open it, say why in the note.'),
            ]);
        }

        if ($reason->needsNote() && $note === null) {
            throw ValidationException::withMessages([
                'disqualificationNote' => $reason->isUnableToContact()
                    ? __('Describe the attempts made to reach them.')
                    : __('Explain why, since ":reason" says little by itself.', ['reason' => $reason->name]),
            ]);
        }
    }

    /**
     * The original of a duplicate must be another Lead that is not itself a
     * duplicate, and a Lead that others repeat cannot become a duplicate itself:
     * duplicates never form a chain.
     *
     * @throws ValidationException
     */
    public static function ensureOriginalIsUsable(Lead $lead, ?Lead $duplicateOf): void
    {
        if ($duplicateOf === null) {
            return;
        }

        if ($duplicateOf->is($lead)) {
            throw ValidationException::withMessages([
                'duplicateOfId' => __('A lead cannot be a duplicate of itself.'),
            ]);
        }

        $duplicateOf = Lead::query()->lockForUpdate()->findOrFail($duplicateOf->id);

        if ($duplicateOf->duplicate_of_lead_id !== null) {
            throw ValidationException::withMessages([
                'duplicateOfId' => __('That lead is itself recorded as a duplicate. Choose the original it repeats.'),
            ]);
        }

        if (Lead::query()->where('duplicate_of_lead_id', $lead->id)->exists()) {
            throw ValidationException::withMessages([
                'duplicateOfId' => __('Another lead is recorded as a duplicate of this one, so this one cannot be a duplicate too.'),
            ]);
        }
    }

    public static function clean(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }
}

<?php

namespace App\Actions\Agreements;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Agreement;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\User;
use App\Support\Money;

/**
 * Mark a sale's signed agreement void when a manager corrects the sale
 * (docs/slice-33-agreement-plan.md, step 33b; decision 211, question 5). It
 * is kept, with who did it, when and why; nothing about it is removed. Called
 * only by CorrectProposalResult, inside its save, after it has checked who
 * may correct a sale.
 */
class VoidAgreement
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    public function apply(Lead $lead, Proposal $proposal, string $reason, User $actor): void
    {
        $agreement = Agreement::query()->where('proposal_id', $proposal->id)->whereNull('voided_at')->lockForUpdate()->first();

        if ($agreement === null) {
            return;
        }

        $agreement->forceFill([
            'voided_at' => now(),
            'voided_by' => $actor->id,
            'voided_by_name' => $actor->name,
            'void_reason' => $reason,
        ])->save();

        $this->recordAuditEvent->handle(
            AuditAction::LeadAgreementVoided,
            $lead,
            actor: $actor,
            before: ['agreement' => 'signed', 'option' => $agreement->details['option']['name'], 'total' => Money::format($agreement->total_cents)],
            after: ['agreement' => 'void'],
            location: $lead->location,
            reason: $reason,
        );
    }
}

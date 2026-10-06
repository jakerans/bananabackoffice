<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnlinkLeadCustomer
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Take a Lead off the customer it was linked to, when the link was wrong.
     *
     * The customer, its people and its properties are untouched, and so is the
     * inquiry on the Lead. Only the three links are cleared, together. A Lead
     * that is not linked is left alone and nothing is recorded.
     *
     * The caller is responsible for checking that the person may do this.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead): void
    {
        if (! $lead->isLinkedToCustomer()) {
            return;
        }

        if (! $lead->isActive()) {
            throw ValidationException::withMessages([
                'customerChoice' => __('This lead is closed, so its customer can no longer be changed.'),
            ]);
        }

        DB::transaction(function () use ($lead): void {
            $before = ResolveLeadCustomer::describe($lead->account, $lead->contact, $lead->property);

            $lead->forceFill(['account_id' => null, 'contact_id' => null, 'property_id' => null])->save();

            $lead->setRelation('account', null);
            $lead->setRelation('contact', null);
            $lead->setRelation('property', null);

            $this->recordAuditEvent->handle(
                AuditAction::LeadCustomerUnlinked,
                $lead,
                before: $before,
                location: $lead->location,
            );
        });
    }
}

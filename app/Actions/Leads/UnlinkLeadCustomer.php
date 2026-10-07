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
    public function handle(
        Lead $lead,
        ?int $expectedAccountId = null,
        ?int $expectedContactId = null,
        ?int $expectedPropertyId = null,
    ): void {
        DB::transaction(function () use ($lead, $expectedAccountId, $expectedContactId, $expectedPropertyId): void {
            $current = Lead::query()->lockForUpdate()->findOrFail($lead->id);

            if ($expectedAccountId !== null && (
                $current->account_id !== $expectedAccountId
                || $current->contact_id !== $expectedContactId
                || $current->property_id !== $expectedPropertyId
            )) {
                $this->throwStaleRelationship();
            }

            if (! $current->isLinkedToCustomer()) {
                return;
            }

            if (! $current->isActive()) {
                throw ValidationException::withMessages([
                    'customerChoice' => __('This lead is closed, so its customer can no longer be changed.'),
                ]);
            }

            $before = ResolveLeadCustomer::describe($current->account, $current->contact, $current->property);

            $changed = Lead::query()
                ->whereKey($current->id)
                ->where('account_id', $current->account_id)
                ->where('contact_id', $current->contact_id)
                ->where('property_id', $current->property_id)
                ->update(['account_id' => null, 'contact_id' => null, 'property_id' => null]);

            if ($changed !== 1) {
                $this->throwStaleRelationship();
            }

            $current->forceFill(['account_id' => null, 'contact_id' => null, 'property_id' => null]);
            $current->setRelation('account', null);
            $current->setRelation('contact', null);
            $current->setRelation('property', null);

            $this->recordAuditEvent->handle(
                AuditAction::LeadCustomerUnlinked,
                $current,
                before: $before,
                location: $current->location,
            );
        });

        $lead->refresh();
    }

    /**
     * Refuse to remove a relationship that no longer matches the one confirmed.
     *
     * @throws ValidationException
     */
    private function throwStaleRelationship(): never
    {
        throw ValidationException::withMessages([
            'customerChoice' => __('This lead\'s customer link was just changed by someone else. Review the current customer before removing it.'),
        ]);
    }
}

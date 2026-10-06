<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArchiveProperty
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Archive a Property, hiding it from the customer and from choices.
     *
     * It is kept, with who archived it and when, and can be restored. A Property
     * an active Lead points at cannot be archived until that Lead is dealt with.
     *
     * The caller is responsible for checking that the person may do this. It is a
     * company-wide management action.
     *
     * @throws ValidationException
     */
    public function archive(Property $property, User $archivedBy): void
    {
        LinkContactToAccount::ensureChangeable($property->account);

        if ($property->isArchived()) {
            return;
        }

        $activeLeads = $property->account->leads()->active()->where('property_id', $property->id)->count();

        if ($activeLeads > 0) {
            throw ValidationException::withMessages([
                'property' => trans_choice(
                    '{1} This property is on 1 active lead, so cannot be archived yet.|[2,*] This property is on :count active leads, so cannot be archived yet.',
                    $activeLeads,
                    ['count' => $activeLeads],
                ),
            ]);
        }

        DB::transaction(function () use ($property, $archivedBy): void {
            $property->forceFill(['archived_at' => now(), 'archived_by' => $archivedBy->id])->save();

            $this->recordAuditEvent->handle(
                AuditAction::PropertyArchived,
                $property,
                actor: $archivedBy,
                before: ['label' => $property->label, 'address' => $property->address],
            );
        });
    }

    /**
     * Restore an archived Property to the customer.
     *
     * @throws ValidationException
     */
    public function restore(Property $property): void
    {
        LinkContactToAccount::ensureChangeable($property->account);

        if (! $property->isArchived()) {
            return;
        }

        DB::transaction(function () use ($property): void {
            $property->forceFill(['archived_at' => null, 'archived_by' => null])->save();

            $this->recordAuditEvent->handle(
                AuditAction::PropertyRestored,
                $property,
                after: ['label' => $property->label, 'address' => $property->address],
            );
        });
    }
}

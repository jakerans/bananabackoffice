<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartWorkingLead
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private AssignLead $assignLead,
    ) {}

    /**
     * Move a New Lead to Working because someone has deliberately started on it.
     *
     * This is the only way a Lead becomes Working. Correcting or adding
     * information never does it. A Lead that is not New is left as it is.
     *
     * A Lead is worked by the person responsible for it. If nobody is yet, the
     * person starting becomes responsible, which is recorded as an assignment
     * like any other. The caller is responsible for checking that this person may
     * start working it: someone who may not assign Leads cannot pick one up this way.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, User $worker): void
    {
        if ($lead->status !== LeadStatus::New) {
            return;
        }

        if ($lead->isAssigned() && ! $lead->isAssignedTo($worker)) {
            throw ValidationException::withMessages([
                'lead' => __('This lead is assigned to :name. It must be reassigned before someone else works it.', ['name' => $lead->assignee?->name]),
            ]);
        }

        DB::transaction(function () use ($lead, $worker): void {
            $this->assignLead->handle($lead, $worker, $worker);

            $lead->forceFill([
                'status' => LeadStatus::Working,
                'work_started_at' => $lead->work_started_at ?? now(),
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadWorkStarted,
                $lead,
                actor: $worker,
                before: ['status' => LeadStatus::New->value],
                after: ['status' => LeadStatus::Working->value],
                location: $lead->location,
            );
        });
    }
}

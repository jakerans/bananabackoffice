<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class StartWorkingLead
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Move a New Lead to Working because someone has deliberately started on it.
     *
     * This is the only way a Lead becomes Working. Correcting or adding
     * information never does it. A Lead that is not New is left as it is.
     */
    public function handle(Lead $lead): void
    {
        if ($lead->status !== LeadStatus::New) {
            return;
        }

        DB::transaction(function () use ($lead): void {
            $lead->status = LeadStatus::Working;
            $lead->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadWorkStarted,
                $lead,
                before: ['status' => LeadStatus::New->value],
                after: ['status' => LeadStatus::Working->value],
                location: $lead->location,
            );
        });
    }
}

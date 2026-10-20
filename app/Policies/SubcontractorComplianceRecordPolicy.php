<?php

namespace App\Policies;

use App\Models\SubcontractorComplianceRecord;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see and attach the documents of a compliance record
 * (docs/slice-16-document-storage-plan.md, step 16b, "Who may do what").
 *
 * The subcontractor decides: those who may see the subcontractor open its
 * documents, and those who may change it attach and remove them.
 */
class SubcontractorComplianceRecordPolicy
{
    public function view(User $user, SubcontractorComplianceRecord $record): bool
    {
        return Gate::forUser($user)->allows('view', $record->subcontractor);
    }

    public function update(User $user, SubcontractorComplianceRecord $record): bool
    {
        return Gate::forUser($user)->allows('update', $record->subcontractor);
    }
}

<?php

namespace App\Actions\Subcontractors;

use App\Actions\Attachments\AttachFile;
use App\Models\Attachment;
use App\Models\SubcontractorComplianceRecord;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Attach the document to a compliance record: the certificate, the agreement
 * or the waiver (docs/slice-16-document-storage-plan.md, step 16b).
 *
 * Only to the record in force for its item; a renewed item takes its new
 * document on its new record, and the earlier one stays with the earlier
 * record. Never to the W-9 (decision 157, question 4) or to Banana's
 * coverage.
 */
class AttachComplianceDocument
{
    public function __construct(private AttachFile $attachFile) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(SubcontractorComplianceRecord $record, UploadedFile $file, User $actor): Attachment
    {
        $record = SubcontractorComplianceRecord::query()->with(['requirement', 'subcontractor.locations'])->findOrFail($record->id);

        if (! $record->takesDocuments()) {
            throw ValidationException::withMessages(['attachment' => $record->uses_banana_coverage
                ? __('They use Banana\'s coverage for this, so there is no document of theirs to attach.')
                : __('The W-9 file is not kept in Backoffice, as it carries a tax ID number. Keep it where you keep it today.')]);
        }

        $latest = (int) SubcontractorComplianceRecord::query()
            ->where('subcontractor_id', $record->subcontractor_id)
            ->where('compliance_requirement_id', $record->compliance_requirement_id)
            ->max('id');

        if ($latest !== $record->id) {
            throw ValidationException::withMessages(['attachment' => __('This was renewed since. Reload the page and attach it to the new one.')]);
        }

        return $this->attachFile->handle($record, SubcontractorComplianceRecord::DOCUMENT, $file, 'update', $actor);
    }
}

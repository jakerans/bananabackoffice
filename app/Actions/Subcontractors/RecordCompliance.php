<?php

namespace App\Actions\Subcontractors;

use App\Actions\Attachments\AttachFile;
use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\ComplianceRequirement;
use App\Models\Subcontractor;
use App\Models\SubcontractorComplianceRecord;
use App\Models\User;
use App\Support\Projects\RoughTimeframe;
use App\Support\Subcontractors\Compliance;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Record that a compliance item was received from a subcontractor, with the
 * day it expires where it does, or that they use Banana's coverage instead
 * (docs/slice-12-subcontractors-plan.md, step 12b; product-architecture.md,
 * 7.2; decision 148).
 *
 * The Production Manager or a manager at one of their Locations. Renewing is
 * recording it again; the earlier record is kept. The document can be
 * attached at the same time (slice 16, step 16b): the record and its
 * document are saved together, or neither is.
 */
class RecordCompliance
{
    public function __construct(private RecordAuditEvent $recordAuditEvent, private AttachComplianceDocument $attachComplianceDocument) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Subcontractor $subcontractor, int $requirementId, ?string $receivedOn, ?string $expiresOn, bool $usesBananaCoverage, ?string $note, User $actor, ?UploadedFile $document = null): SubcontractorComplianceRecord
    {
        if ($document !== null) {
            AttachFile::ensureAccepted($document);
        }

        return DB::transaction(function () use ($subcontractor, $requirementId, $receivedOn, $expiresOn, $usesBananaCoverage, $note, $actor, $document): SubcontractorComplianceRecord {
            // Read as it is now, and held so two records cannot cross.
            $subcontractor = Subcontractor::query()->with(['locations', 'complianceRecords'])->lockForUpdate()->findOrFail($subcontractor->id);

            Gate::forUser($actor)->authorize('update', $subcontractor);

            $requirement = ComplianceRequirement::query()->active()->find($requirementId);

            if ($requirement === null || ! Compliance::for($subcontractor)->applies($requirement->id)) {
                throw ValidationException::withMessages(['compliance' => __('This is not one of their requirements. Reload the page.')]);
            }

            $values = self::read($subcontractor, $requirement, $receivedOn, $expiresOn, $usesBananaCoverage, $note);

            $record = new SubcontractorComplianceRecord;
            $record->forceFill([
                'subcontractor_id' => $subcontractor->id,
                'compliance_requirement_id' => $requirement->id,
                ...$values,
                'recorded_by' => $actor->id,
                'recorded_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::SubcontractorComplianceRecorded,
                $subcontractor,
                actor: $actor,
                after: [
                    'requirement' => $requirement->name,
                    'received_on' => $record->received_on?->toDateString(),
                    'expires_on' => $record->expires_on?->toDateString(),
                    'uses_banana_coverage' => $record->uses_banana_coverage,
                ],
                location: $subcontractor->locations->first(),
                reason: $record->note,
            );

            if ($document !== null) {
                $this->attachComplianceDocument->handle($record, $document, $actor);
            }

            return $record;
        });
    }

    /**
     * Read what was typed: the day received, which cannot be in the future;
     * the day it expires, when the item does, which cannot have passed; or
     * Banana's coverage, where the item allows it.
     *
     * @return array{received_on: ?CarbonImmutable, expires_on: ?CarbonImmutable, uses_banana_coverage: bool, note: ?string}
     *
     * @throws ValidationException
     */
    private static function read(Subcontractor $subcontractor, ComplianceRequirement $requirement, ?string $receivedOn, ?string $expiresOn, bool $usesBananaCoverage, ?string $note): array
    {
        $today = RoughTimeframe::today($subcontractor->locations->first());
        $note = trim((string) $note);

        if (mb_strlen($note) > SubcontractorComplianceRecord::NOTE_LENGTH) {
            throw ValidationException::withMessages(['complianceNote' => __('Keep this to :max characters or fewer.', ['max' => SubcontractorComplianceRecord::NOTE_LENGTH])]);
        }

        if ($usesBananaCoverage) {
            if (! $requirement->allows_banana_coverage) {
                throw ValidationException::withMessages(['complianceCoverage' => __('Banana\'s coverage cannot stand in for this.')]);
            }

            return ['received_on' => null, 'expires_on' => null, 'uses_banana_coverage' => true, 'note' => $note === '' ? null : $note];
        }

        $received = RoughTimeframe::typed($receivedOn, 'complianceReceivedOn', __('Choose the day it was received.'));

        if ($received->greaterThan($today)) {
            throw ValidationException::withMessages(['complianceReceivedOn' => __('The day it was received cannot be in the future.')]);
        }

        $expires = null;

        if ($requirement->expires) {
            $expires = RoughTimeframe::typed($expiresOn, 'complianceExpiresOn', __('Choose the day it expires.'));

            if ($expires->lessThan($today)) {
                throw ValidationException::withMessages(['complianceExpiresOn' => __('This has already expired. Record the renewed one.')]);
            }
        }

        return ['received_on' => $received, 'expires_on' => $expires, 'uses_banana_coverage' => false, 'note' => $note === '' ? null : $note];
    }
}

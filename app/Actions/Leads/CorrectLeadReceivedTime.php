<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Lead;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectLeadReceivedTime
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Correct when a Lead was received.
     *
     * Response times are measured from this moment, so a correction always needs
     * a reason, and the time before and after are recorded. It can never be in
     * the future. Saving the time it already has changes nothing and records nothing.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, CarbonInterface $receivedAt, string $reason): void
    {
        if ($receivedAt->isAfter(now()->addMinute())) {
            throw ValidationException::withMessages([
                'correctedReceivedAt' => __('The received time cannot be in the future.'),
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'receivedTimeReason' => __('Say why the received time is being corrected.'),
            ]);
        }

        // The form works to the minute, so the same minute is the same time.
        if ($lead->received_at->startOfMinute()->equalTo($receivedAt->startOfMinute())) {
            return;
        }

        DB::transaction(function () use ($lead, $receivedAt, $reason): void {
            $previous = $lead->received_at;

            $lead->forceFill(['received_at' => $receivedAt])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadReceivedTimeCorrected,
                $lead,
                before: ['received_at' => $previous->toIso8601String()],
                after: ['received_at' => $lead->received_at->toIso8601String()],
                location: $lead->location,
                reason: trim($reason),
            );
        });
    }
}

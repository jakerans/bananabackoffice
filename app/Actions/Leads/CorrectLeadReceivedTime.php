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
     * A Lead is received before anything else happens to it. The corrected time
     * may therefore not be later than anything already recorded about the Lead:
     * when it was entered, first routed, first assigned, or first worked. Those
     * times are never adjusted to make a correction fit; the correction is refused.
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

        $this->ensureNothingHappenedEarlier($lead, $receivedAt);

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

    /**
     * Refuse a received time that falls after something already recorded about the Lead.
     *
     * The earliest such moment is the limit, and it is the one named in the message.
     *
     * @throws ValidationException
     */
    private function ensureNothingHappenedEarlier(Lead $lead, CarbonInterface $receivedAt): void
    {
        $firstAssignedAt = $lead->assignments()->oldest('assigned_at')->first()?->assigned_at;

        $milestones = array_filter([
            __('entered in Backoffice') => $lead->created_at,
            __('first routed to a location') => $lead->first_routed_at,
            __('first assigned') => $firstAssignedAt,
            __('first worked') => $lead->work_started_at,
        ]);

        $limit = null;
        $limitedBy = null;

        foreach ($milestones as $what => $when) {
            if ($limit === null || $when->isBefore($limit)) {
                $limit = $when;
                $limitedBy = $what;
            }
        }

        // To the minute, like the form: the minute something happened in is still allowed.
        if ($limit === null || ! $receivedAt->startOfMinute()->isAfter($limit)) {
            return;
        }

        throw ValidationException::withMessages([
            'correctedReceivedAt' => __('The received time cannot be after the lead was :what (:when). Nothing else about the lead was changed.', [
                'what' => $limitedBy,
                'when' => $limit->setTimezone(config('app.display_timezone'))->format('M j, Y g:i A T'),
            ]),
        ]);
    }
}

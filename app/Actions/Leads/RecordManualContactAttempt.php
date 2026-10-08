<?php

namespace App\Actions\Leads;

use App\Enums\ContactAttemptChannel;
use App\Enums\ContactAttemptDirection;
use App\Enums\ContactAttemptDisposition;
use App\Enums\ContactAttemptLifecycleResult;
use App\Enums\ContactAttemptSource;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\User;
use App\Support\Access\Impersonation;
use App\Support\Contact\PhoneNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordManualContactAttempt
{
    public function __construct(private Impersonation $impersonation) {}

    /**
     * Record a human-entered attempt without changing the Lead in any way.
     *
     * Source, current linked Contact, location, lifecycle state, responsible
     * staff identity and impersonation provenance are derived here, never from
     * browser-controlled values.
     *
     * @throws ValidationException
     */
    public function handle(
        Lead $lead,
        User $responsibleUser,
        string $submissionId,
        ContactAttemptChannel $channel,
        ContactAttemptDirection $direction,
        CarbonInterface $occurredAt,
        ?string $number,
        ?int $durationSeconds,
        bool $wasAnswered,
        bool $wasConnected,
        ContactAttemptDisposition $disposition,
    ): ContactAttempt {
        $number = trim((string) $number);
        $number = $number === '' ? null : $number;

        $this->validateFacts($submissionId, $channel, $occurredAt, $number, $durationSeconds, $wasAnswered, $wasConnected, $disposition);

        return DB::transaction(function () use ($lead, $responsibleUser, $submissionId, $channel, $direction, $occurredAt, $number, $durationSeconds, $wasAnswered, $wasConnected, $disposition): ContactAttempt {
            $currentLead = Lead::query()->lockForUpdate()->findOrFail($lead->id);

            Gate::forUser($responsibleUser)->authorize('update', $currentLead);

            $impersonator = $this->impersonation->impersonator();
            $submissionFingerprint = $this->submissionFingerprint(
                $currentLead,
                $responsibleUser,
                $impersonator,
                $channel,
                $direction,
                $occurredAt,
                $number,
                $durationSeconds,
                $wasAnswered,
                $wasConnected,
                $disposition,
            );
            $existingAttempt = ContactAttempt::query()
                ->where('lead_id', $currentLead->id)
                ->where('submission_id', $submissionId)
                ->first();

            if ($existingAttempt !== null) {
                if ($existingAttempt->responsible_user_id !== $responsibleUser->id
                    || ! hash_equals($existingAttempt->submission_fingerprint, $submissionFingerprint)) {
                    throw ValidationException::withMessages([
                        'attemptSubmissionId' => __('This contact attempt form is stale. Open a new form and try again.'),
                    ]);
                }

                return $existingAttempt;
            }

            $attempt = new ContactAttempt;

            $attempt->forceFill([
                'lead_id' => $currentLead->id,
                'submission_id' => $submissionId,
                'submission_fingerprint' => $submissionFingerprint,
                'account_id' => $currentLead->account_id,
                'contact_id' => $currentLead->contact_id,
                'location_id' => $currentLead->location_id,
                'responsible_user_id' => $responsibleUser->id,
                'responsible_user_name' => $responsibleUser->name,
                'impersonator_id' => $impersonator?->id,
                'impersonator_name' => $impersonator?->name,
                'source' => ContactAttemptSource::Manual,
                'channel' => $channel,
                'direction' => $direction,
                'occurred_at' => $occurredAt->utc(),
                'number' => $number,
                'number_normalized' => PhoneNumber::normalize($number),
                'duration_seconds' => $durationSeconds,
                'was_answered' => $wasAnswered,
                'was_connected' => $wasConnected,
                'disposition' => $disposition,
                'lead_status' => $currentLead->status,
                'lifecycle_result' => ContactAttemptLifecycleResult::NoChange,
                'recording_reference' => null,
                'transcript_reference' => null,
                'created_at' => now(),
            ])->save();

            return $attempt;
        });
    }

    /** @throws ValidationException */
    private function validateFacts(
        string $submissionId,
        ContactAttemptChannel $channel,
        CarbonInterface $occurredAt,
        ?string $number,
        ?int $durationSeconds,
        bool $wasAnswered,
        bool $wasConnected,
        ContactAttemptDisposition $disposition,
    ): void {
        $errors = [];

        if (! Str::isUuid($submissionId)) {
            $errors['attemptSubmissionId'] = __('Open a new contact attempt form and try again.');
        }

        if ($occurredAt->isFuture()) {
            $errors['attemptOccurredAt'] = __('The attempt time cannot be in the future.');
        }

        if ($channel->requiresNumber() && $number === null) {
            $errors['attemptNumber'] = __('Enter the phone number used for this attempt.');
        }

        if ($channel->supportsDuration() && $durationSeconds === null) {
            $errors['attemptDurationSeconds'] = __('Enter the call duration in seconds.');
        }

        if (! $channel->supportsDuration() && $durationSeconds !== null) {
            $errors['attemptDurationSeconds'] = __('Duration is recorded only for phone calls.');
        }

        if (! $channel->supportsDisposition($disposition)) {
            $errors['attemptDisposition'] = __('The selected disposition is not available for this channel.');
        }

        if ($durationSeconds !== null && ($durationSeconds < 0 || $durationSeconds > 86400)) {
            $errors['attemptDurationSeconds'] = __('Duration must be between 0 and 86,400 seconds.');
        }

        if ($wasConnected && ! $wasAnswered) {
            $errors['attemptConnected'] = __('A two-way connection must also be marked answered.');
        }

        if ($disposition->answered() !== null && $disposition->answered() !== $wasAnswered) {
            $errors['attemptAnswered'] = __('The answered status does not match the selected disposition.');
        }

        if ($disposition->connected() !== null && $disposition->connected() !== $wasConnected) {
            $errors['attemptConnected'] = __('The connection status does not match the selected disposition.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function submissionFingerprint(
        Lead $lead,
        User $responsibleUser,
        ?User $impersonator,
        ContactAttemptChannel $channel,
        ContactAttemptDirection $direction,
        CarbonInterface $occurredAt,
        ?string $number,
        ?int $durationSeconds,
        bool $wasAnswered,
        bool $wasConnected,
        ContactAttemptDisposition $disposition,
    ): string {
        return hash('sha256', json_encode([
            'lead_id' => $lead->id,
            'account_id' => $lead->account_id,
            'contact_id' => $lead->contact_id,
            'location_id' => $lead->location_id,
            'responsible_user_id' => $responsibleUser->id,
            'impersonator_id' => $impersonator?->id,
            'channel' => $channel->value,
            'direction' => $direction->value,
            'occurred_at' => $occurredAt->toImmutable()->utc()->format('Y-m-d\TH:i:s.uP'),
            'number' => $number,
            'duration_seconds' => $durationSeconds,
            'was_answered' => $wasAnswered,
            'was_connected' => $wasConnected,
            'disposition' => $disposition->value,
            'lead_status' => $lead->status->value,
        ], JSON_THROW_ON_ERROR));
    }
}

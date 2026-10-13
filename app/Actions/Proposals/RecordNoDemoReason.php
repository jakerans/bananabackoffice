<?php

namespace App\Actions\Proposals;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecordNoDemoReason
{
    /** The longest reason kept. */
    public const int LENGTH = 1000;

    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Say why nothing was presented at an appointment that was run (decision
     * 133: a no-demo "records the coaching reason"; decision 134). The
     * salesperson the Lead is with, or a manager, in their own words.
     *
     * It changes nothing about how the appointment counts: run with no
     * proposal is a no-demo whatever the reason, until a proposal is added
     * (decision 124). It can be said again; what it was is kept in the audit
     * record. Nothing about the Lead changes.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Appointment $appointment, ?string $reason, User $actor, ?int $expectedVersion = null): void
    {
        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $appointment, $reason, $actor): bool {
            $this->apply($lead, $appointment, $reason, $actor);

            // The Lead's own lifecycle did not change.
            return false;
        });
    }

    /**
     * The same, for a caller that has already claimed the Lead's row and is
     * saving this with other changes: the after-action report.
     *
     * @param  string  $field  The field a refusal is shown at.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function apply(Lead $lead, Appointment $appointment, ?string $reason, User $actor, string $field = 'noDemoReason'): void
    {
        Gate::forUser($actor)->authorize('manageProposals', $lead);

        // Read as it is now; the Lead's row is already claimed, so it cannot change underneath.
        $appointment = Appointment::query()->findOrFail($appointment->id);
        $reason = trim((string) $reason);

        if ($appointment->lead_id !== $lead->id || $appointment->state !== AppointmentState::Run) {
            throw ValidationException::withMessages([$field => __('Only an appointment of this lead that was run has this to say.')]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages([$field => __('Say why nothing was presented.')]);
        }

        if (mb_strlen($reason) > self::LENGTH) {
            throw ValidationException::withMessages([$field => __('Keep this to :max characters or fewer.', ['max' => self::LENGTH])]);
        }

        if ($reason === $appointment->no_demo_reason) {
            return;
        }

        $before = $appointment->no_demo_reason;

        $appointment->forceFill([
            'no_demo_reason' => $reason,
            'no_demo_reason_by' => $actor->id,
            'no_demo_reason_by_name' => $actor->name,
            'no_demo_reason_at' => now(),
        ])->save();

        $this->recordAuditEvent->handle(
            AuditAction::LeadAppointmentNoDemoReason,
            $lead,
            actor: $actor,
            before: $before === null ? [] : ['reason' => $before],
            after: ['reason' => $reason, 'starts_at' => $appointment->starts_at->toIso8601String()],
            location: $lead->location,
        );
    }
}

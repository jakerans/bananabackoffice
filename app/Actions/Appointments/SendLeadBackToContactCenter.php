<?php

namespace App\Actions\Appointments;

use App\Actions\Leads\ChangeLeadLifecycle;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SendLeadBackToContactCenter
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private ReturnLeadFromSales $returnLeadFromSales,
    ) {}

    /**
     * Send a Lead that is with Sales back to Contact Center (rulebook, rule
     * 9): a manager, with a note.
     *
     * The Lead shows Qualified, is assigned to nobody and starts the call
     * cadence over (decisions 97, 103 and 105). An appointment still upcoming
     * is cancelled in the same step (decision 111). Whether the Lead still
     * counts as issued depends on the answer given (decisions 100 and 110):
     * "it should never have been booked" voids its issue; any other reason
     * leaves it counted. All of it is saved together or not at all.
     *
     * Who may do this is checked here again, on the Lead as it is now. If the
     * database reports a deadlock or says it is busy, the whole change is
     * tried again (decision 87).
     *
     * @param  string|null  $issuedCount  One of the StopCountingLeadAsIssued answers.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, ?string $issuedCount, ?string $note, User $actor, ?int $expectedVersion = null): void
    {
        $note = trim((string) $note);

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $issuedCount, $note, $actor): bool {
            Gate::forUser($actor)->authorize('manageAppointment', $lead);

            if (! $lead->isWithSales()) {
                throw ValidationException::withMessages([
                    'sendBackNote' => __('This lead is not with Sales, so there is nothing to send back.'),
                ]);
            }

            if (! in_array($issuedCount, [StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED, StopCountingLeadAsIssued::ANOTHER_REASON], true)) {
                throw ValidationException::withMessages([
                    'issuedCount' => __('Say whether this lead should ever have been booked.'),
                ]);
            }

            if ($note === '') {
                throw ValidationException::withMessages([
                    'sendBackNote' => __('Say why this lead is going back to the Contact Center.'),
                ]);
            }

            $neverShouldHaveBeenBooked = $issuedCount === StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED;

            $this->returnLeadFromSales->settleIssue($lead, $actor, $neverShouldHaveBeenBooked);
            $this->returnLeadFromSales->handle($lead, $actor, ReturnLeadFromSales::SENT_BACK, $note, ! $neverShouldHaveBeenBooked, $lead->location);

            return true;
        }, retryWhenBusy: true);
    }
}

<?php

namespace App\Actions\Appointments;

use App\Models\Lead;
use App\Models\LeadIssue;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StopCountingLeadAsIssued
{
    /**
     * The words the two answers to "does it still count as issued?" are shown
     * with (decision 110).
     */
    public const string NEVER_SHOULD_HAVE_BEEN_BOOKED = 'never_booked';

    public const string ANOTHER_REASON = 'another_reason';

    /**
     * Take away the issue of a Lead that is leaving Sales because it should
     * never have been booked (decisions 100 and 110). The issue it is counted
     * by is marked voided, as "booked in error" does (rulebook, rule 10), and
     * the Lead's two issue times are worked out again from what still counts.
     *
     * Only a manager where the Lead is may say this. Check and change happen
     * here, on the Lead as it is before it leaves, so call this before the
     * Lead's Location changes.
     *
     * The caller must be inside a transaction, having written or locked the
     * Lead row first.
     *
     * @return bool Whether an issue was voided.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, User $actor): bool
    {
        if (! Gate::forUser($actor)->allows('manageAppointment', $lead)) {
            throw ValidationException::withMessages([
                'issuedCount' => __('Only a manager can say a lead should never have been booked.'),
            ]);
        }

        $issue = LeadIssue::query()->where('lead_id', $lead->id)->counted()->orderByDesc('id')->lockForUpdate()->first();

        if ($issue === null) {
            return false;
        }

        $issue->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'voided_by_name' => $actor->name])->save();

        $counted = LeadIssue::query()->where('lead_id', $lead->id)->counted();

        $lead->forceFill([
            'first_issued_at' => (clone $counted)->min('issued_at'),
            'last_issued_at' => (clone $counted)->max('issued_at'),
        ])->save();

        return true;
    }
}

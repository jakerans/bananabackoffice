<?php

namespace App\Actions\Scorecards;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\ScorecardLine;
use App\Models\User;
use App\Support\Scorecards\ScorecardOwnership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correct the status or note of one KPI on a submitted scorecard
 * (docs/slice-27-leadership-scorecards-plan.md, step 27a; decision 187,
 * question 6): its owner, with a reason kept in the audit trail. Its numbers
 * stay as they were submitted.
 */
class CorrectScorecardLine
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(ScorecardLine $line, string $status, ?string $note, ?string $reason, User $actor): void
    {
        if (! ScorecardOwnership::canSubmit($actor, $line->scorecard->location)) {
            throw new AuthorizationException;
        }

        $note = trim((string) $note);
        $reason = trim((string) $reason);
        $errors = [];

        if (! array_key_exists($status, ScorecardLine::STATUSES)) {
            $errors['correctStatus'] = __('Choose Green, Yellow or Red.');
        } elseif ($status !== ScorecardLine::GREEN && $note === '') {
            $errors['correctNote'] = __('Say briefly why, and what is being done.');
        }

        if (mb_strlen($note) > ScorecardLine::NOTE_LENGTH) {
            $errors['correctNote'] = __('Keep this to :max characters or fewer.', ['max' => ScorecardLine::NOTE_LENGTH]);
        }

        if ($reason === '') {
            $errors['correctReason'] = __('Say why it is being corrected.');
        } elseif (mb_strlen($reason) > ScorecardLine::NOTE_LENGTH) {
            $errors['correctReason'] = __('Keep this to :max characters or fewer.', ['max' => ScorecardLine::NOTE_LENGTH]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($line, $status, $note, $reason, $actor): void {
            $line = ScorecardLine::query()->with('scorecard.location')->lockForUpdate()->findOrFail($line->id);
            $before = ['kpi' => $line->kpi, 'status' => $line->status, 'note' => $line->note];

            $line->forceFill(['status' => $status, 'note' => $note === '' ? null : $note])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ScorecardLineCorrected,
                $line->scorecard,
                actor: $actor,
                before: $before,
                after: ['kpi' => $line->kpi, 'status' => $line->status, 'note' => $line->note],
                location: $line->scorecard->location,
                reason: $reason,
            );
        });
    }
}

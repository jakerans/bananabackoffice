<?php

namespace App\Actions\Scorecards;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Location;
use App\Models\Scorecard;
use App\Models\ScorecardLine;
use App\Models\User;
use App\Support\Scorecards\ScorecardNumbers;
use App\Support\Scorecards\ScorecardOwnership;
use App\Support\Scorecards\ScorecardWeek;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Submit a Location's scorecard for a week that has ended
 * (docs/slice-27-leadership-scorecards-plan.md, step 27a; decision 187,
 * questions 5 and 6): its owner chooses Green, Yellow or Red for each KPI,
 * with a note for any that is not Green. Its numbers are kept as they are
 * now, so later corrections to the records do not change its story.
 */
class SubmitScorecard
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array<string, string>  $statuses  Each KPI's status.
     * @param  array<string, string|null>  $notes  Each KPI's note.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Location $location, string $week, array $statuses, array $notes, User $actor): Scorecard
    {
        $location = Location::query()->findOrFail($location->id);

        if (! ScorecardOwnership::canSubmit($actor, $location)) {
            throw new AuthorizationException;
        }

        $monday = ScorecardWeek::parse($week);

        if ($monday === null || ! ScorecardWeek::hasEnded($location, $monday)) {
            throw ValidationException::withMessages(['week' => __('Choose a week that has ended.')]);
        }

        $chosen = $this->chosen($statuses, $notes);

        return DB::transaction(function () use ($location, $monday, $chosen, $actor): Scorecard {
            $location = Location::query()->lockForUpdate()->findOrFail($location->id);

            if (Scorecard::query()->where('location_id', $location->id)->whereDate('week_starts_on', $monday->toDateString())->exists()) {
                throw ValidationException::withMessages(['week' => __('This week\'s scorecard is already submitted.')]);
            }

            $scorecard = new Scorecard;
            $scorecard->forceFill([
                'location_id' => $location->id,
                'week_starts_on' => $monday->toDateString(),
                'month' => ScorecardWeek::month($monday),
                'owner_id' => $actor->id,
                'owner_name' => $actor->name,
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
                'submitted_by_name' => $actor->name,
            ])->save();

            foreach (ScorecardNumbers::for($location, $monday) as $position => $number) {
                (new ScorecardLine)->forceFill([
                    'scorecard_id' => $scorecard->id,
                    'kpi' => $number['kpi'],
                    'position' => $position + 1,
                    'actual' => $number['actual'],
                    'target' => $number['target'],
                    'target_due' => $number['due'],
                    'on_target' => $number['onTarget'],
                    'status' => $chosen[$number['kpi']]['status'],
                    'note' => $chosen[$number['kpi']]['note'],
                ])->save();
            }

            $this->recordAuditEvent->handle(
                AuditAction::ScorecardSubmitted,
                $scorecard,
                actor: $actor,
                after: ['week' => $monday->toDateString(), 'statuses' => array_map(fn (array $line): string => $line['status'], $chosen)],
                location: $location,
            );

            return $scorecard;
        });
    }

    /**
     * @param  array<string, string>  $statuses
     * @param  array<string, string|null>  $notes
     * @return array<string, array{status: string, note: string|null}>
     *
     * @throws ValidationException
     */
    private function chosen(array $statuses, array $notes): array
    {
        $errors = [];
        $chosen = [];

        foreach (array_keys(ScorecardNumbers::KPIS) as $kpi) {
            $status = $statuses[$kpi] ?? '';
            $note = trim((string) ($notes[$kpi] ?? ''));

            if (! array_key_exists($status, ScorecardLine::STATUSES)) {
                $errors["statuses.{$kpi}"] = __('Choose Green, Yellow or Red.');
            } elseif ($status !== ScorecardLine::GREEN && $note === '') {
                $errors["notes.{$kpi}"] = __('Say briefly why, and what is being done.');
            }

            if (mb_strlen($note) > ScorecardLine::NOTE_LENGTH) {
                $errors["notes.{$kpi}"] = __('Keep this to :max characters or fewer.', ['max' => ScorecardLine::NOTE_LENGTH]);
            }

            $chosen[$kpi] = ['status' => $status, 'note' => $note === '' ? null : $note];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $chosen;
    }
}

<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectNotice;
use App\Support\Queue\CallingHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class RemindToCompleteHandoff
{
    /** The hour of the next morning, in the Location's time, when the reminder is due (decision 132). */
    public const int MORNING_HOUR = 8;

    /**
     * The Projects whose salesperson is due the reminder: sold, still
     * standing, the handoff not complete, a salesperson to remind, not
     * reminded yet, and it is the morning after the sale in the Project's own
     * Location.
     *
     * @return list<int>
     */
    public static function due(CarbonInterface $moment): array
    {
        $due = Project::query()
            ->standing()
            ->whereDoesntHave('handoff')
            ->whereNull('handoff_reminded_at')
            ->whereNotNull('salesperson_id')
            ->where('sold_at', '<', $moment)
            ->with('location')
            ->orderBy('id')
            ->get()
            ->filter(fn (Project $project): bool => $moment->gte(self::dueAt($project)));

        return array_values($due->map(fn (Project $project): int => $project->id)->all());
    }

    /**
     * When a Project's reminder is due: 8:00 on the morning after the sale,
     * by the Location's own clock.
     */
    public static function dueAt(Project $project): CarbonInterface
    {
        return $project->sold_at->copy()
            ->timezone(CallingHours::timezoneFor($project->location))
            ->addDay()
            ->setTime(self::MORNING_HOUR, 0);
    }

    /**
     * Remind one Project's salesperson, once, to answer the handoff
     * questions: a notice inside the app and an email (decision 132). A thin
     * handover leaves the Production Manager guessing; the prompt on every
     * page is the first nudge and this is the second.
     *
     * Neither carries anything about the customer. The notice is saved with
     * the mark that it was sent; the email goes after, and if it cannot be
     * sent the failure is reported and the reminder is not repeated.
     *
     * @return bool Whether the reminder was sent.
     */
    public function handle(int $projectId): bool
    {
        $salesperson = DB::transaction(function () use ($projectId): ?User {
            $project = Project::query()->lockForUpdate()->find($projectId);

            if ($project === null || ! in_array($project->id, self::due(now()), true)) {
                return null;
            }

            $salesperson = User::query()->where('is_active', true)->find($project->salesperson_id);

            if ($salesperson === null) {
                return null;
            }

            $project->forceFill(['handoff_reminded_at' => now()])->save();
            $salesperson->notify(new ProjectNotice($project->id, ProjectNotice::HANDOFF_REMINDER));

            return $salesperson;
        });

        if ($salesperson === null) {
            return false;
        }

        try {
            $salesperson->notify(new ProjectNotice($projectId, ProjectNotice::HANDOFF_REMINDER, ProjectNotice::EMAIL));
        } catch (Throwable $exception) {
            report($exception);
        }

        return true;
    }
}

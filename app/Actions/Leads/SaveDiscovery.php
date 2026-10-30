<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Lead;
use App\Models\LeadDiscovery;
use App\Models\ProjectHandoff;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Write down what the customer said in discovery (WNBET)
 * (docs/slice-34-sales-workspace-plan.md, step 34b; decision 212): their
 * Wants, Needs, Budget, Expectations and Timeline, each optional. The people
 * who manage the Lead's estimates, until a handoff to Production is complete
 * for one of its Projects; the handoff then holds what was learned.
 *
 * The Audit Log records which answers changed, not what they say.
 */
class SaveDiscovery
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array<string, mixed>  $answers  By column: wants, needs, budget, expectations, timeline.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Lead $lead, array $answers, User $actor): LeadDiscovery
    {
        Gate::forUser($actor)->authorize('manageProposals', $lead);

        $clean = [];

        foreach (array_keys(LeadDiscovery::ANSWERS) as $column) {
            $answer = trim((string) ($answers[$column] ?? ''));

            if (mb_strlen($answer) > LeadDiscovery::ANSWER_LENGTH) {
                throw ValidationException::withMessages(["discovery.{$column}" => __('Keep it to :max characters.', ['max' => LeadDiscovery::ANSWER_LENGTH])]);
            }

            $clean[$column] = $answer === '' ? null : $answer;
        }

        return DB::transaction(function () use ($lead, $clean, $actor): LeadDiscovery {
            if (self::isSettled($lead)) {
                throw ValidationException::withMessages(['discovery' => __('The handoff to Production is complete, and holds what was learned. Discovery can no longer be changed here.')]);
            }

            $discovery = LeadDiscovery::query()->where('lead_id', $lead->id)->lockForUpdate()->first() ?? new LeadDiscovery;
            $changed = array_keys(array_filter($clean, fn (?string $answer, string $column): bool => $answer !== $discovery->getAttribute($column), ARRAY_FILTER_USE_BOTH));

            if ($changed === []) {
                return $discovery;
            }

            $discovery->forceFill([...$clean, 'lead_id' => $lead->id, 'updated_by' => $actor->id, 'updated_by_name' => $actor->name])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadDiscoverySaved,
                $lead,
                actor: $actor,
                after: [
                    'changed' => implode(', ', array_map(fn (string $column): string => LeadDiscovery::ANSWERS[$column], $changed)),
                    'answered' => count($discovery->answered()),
                ],
                location: $lead->location,
            );

            return $discovery;
        });
    }

    /**
     * Whether discovery is kept as it is: a handoff to Production is complete for one of the Lead's Projects.
     */
    public static function isSettled(Lead $lead): bool
    {
        return ProjectHandoff::query()->whereHas('project', fn ($project) => $project->where('lead_id', $lead->id))->exists();
    }
}

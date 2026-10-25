<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Compensation\RecordCommission;
use App\Enums\AuditAction;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Service;
use App\Models\User;
use App\Support\Addresses\AddressKey;

class CreateProjectForSoldProposal
{
    public function __construct(private RecordAuditEvent $recordAuditEvent, private RecordCommission $recordCommission) {}

    /**
     * Make the Project for a proposal that has just been marked Sold
     * (docs/slice-8-projects-plan.md, step 8a; rulebook, rule 13). Nobody does
     * this by hand: it is part of the sale and is saved with it or not at all.
     * The caller has already claimed the Lead's row.
     *
     * Each sold proposal has one Project of its own (decision 130). It takes
     * the Lead's Location, customer, contact, services and project size as
     * they are now, the proposal's property and sold amount, and the
     * salesperson the Lead is with. It starts as "New from Sales".
     *
     * If this proposal was sold before and that sale was corrected, its
     * Project is brought back with the new amount and day, not made again.
     *
     * Either way its salesperson's commission is projected (slice 20, step
     * 20a).
     */
    public function apply(Lead $lead, Proposal $proposal, User $actor): Project
    {
        $existing = Project::query()->where('proposal_id', $proposal->id)->first();

        if ($existing !== null) {
            return $this->bringBack($existing, $lead, $proposal, $actor);
        }

        $title = self::titleFor($proposal);

        $project = new Project;
        $project->forceFill([
            'proposal_id' => $proposal->id,
            'lead_id' => $lead->id,
            'location_id' => $lead->location_id,
            'account_id' => $lead->account_id,
            'contact_id' => $lead->contact_id,
            // The proposal's own property: one Lead can sell work at more than one house (decision 132).
            'property_id' => $proposal->property_id,
            'title' => $title,
            'project_size_id' => $lead->project_size_id,
            'project_size_min_value' => $lead->project_size_min_value,
            'project_size_max_value' => $lead->project_size_max_value,
            'sold_cents' => $proposal->sold_cents,
            'sold_at' => $proposal->result_at,
            'salesperson_id' => $lead->assigned_user_id,
            'salesperson_name' => $lead->assignee?->name,
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
        ])->save();

        $project->services()->attach($lead->services()->get()->mapWithKeys(
            fn (Service $service): array => [$service->id => ['is_primary' => (bool) $service->getRelationValue('pivot')?->getAttribute('is_primary')]],
        )->all());

        $this->recordAuditEvent->handle(
            AuditAction::ProjectCreated,
            $project,
            actor: $actor,
            after: ['title' => $title, 'proposal' => $proposal->name, 'sold_for' => $project->soldFor(), 'salesperson' => $project->salesperson_name],
            location: $lead->location,
        );

        $this->recordCommission->sold($project, $actor);

        return $project;
    }

    /**
     * The title a Project starts with: what was sold and the short street
     * address of the property it is for, "Lake house deck — 9 Lake Rd"
     * (decision 134, which changes answer 3 of decision 130). One Lead can
     * have several Projects, so each is named for its own proposal, not for
     * the Lead's main service. It can be edited afterwards.
     */
    public static function titleFor(Proposal $proposal): string
    {
        $property = $proposal->property;
        $street = $property === null ? null : ($property->address_line1 === null ? $property->address : AddressKey::shortStreet($property->address_line1));
        $title = $street === null || $street === '' ? $proposal->name : $proposal->name.' — '.$street;

        return mb_substr($title, 0, Project::TITLE_LENGTH);
    }

    private function bringBack(Project $project, Lead $lead, Proposal $proposal, User $actor): Project
    {
        $project->forceFill([
            'sold_cents' => $proposal->sold_cents,
            'sold_at' => $proposal->result_at,
            'salesperson_id' => $lead->assigned_user_id,
            'salesperson_name' => $lead->assignee?->name,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancelled_by_name' => null,
            'cancellation_note' => null,
        ])->save();

        $this->recordAuditEvent->handle(
            AuditAction::ProjectBroughtBack,
            $project,
            actor: $actor,
            before: ['cancelled' => true],
            after: ['cancelled' => false, 'title' => $project->title, 'sold_for' => $project->soldFor()],
            location: $project->location,
        );

        $this->recordCommission->sold($project, $actor);

        return $project;
    }
}

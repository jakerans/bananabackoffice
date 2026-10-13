<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Service;
use App\Models\User;
use App\Support\Addresses\AddressKey;

class CreateProjectForSoldProposal
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Make the Project for a proposal that has just been marked Sold
     * (docs/slice-8-projects-plan.md, step 8a; rulebook, rule 13). Nobody does
     * this by hand: it is part of the sale and is saved with it or not at all.
     * The caller has already claimed the Lead's row.
     *
     * Each sold proposal has one Project of its own (decision 130). It takes
     * the Lead's Location, customer, contact, property, services and project
     * size as they are now, the proposal's sold amount, and the salesperson
     * the Lead is with. It starts as "New from Sales".
     *
     * If this proposal was sold before and that sale was corrected, its
     * Project is brought back with the new amount and day, not made again.
     */
    public function apply(Lead $lead, Proposal $proposal, User $actor): Project
    {
        $existing = Project::query()->where('proposal_id', $proposal->id)->first();

        if ($existing !== null) {
            return $this->bringBack($existing, $lead, $proposal, $actor);
        }

        $title = self::titleFor($lead, $proposal);

        $project = new Project;
        $project->forceFill([
            'proposal_id' => $proposal->id,
            'lead_id' => $lead->id,
            'location_id' => $lead->location_id,
            'account_id' => $lead->account_id,
            'contact_id' => $lead->contact_id,
            'property_id' => $lead->property_id,
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

        return $project;
    }

    /**
     * The title a Project starts with: the Lead's main service and the
     * property's short street address, "Exterior Painting — 123 Oak St"
     * (decision 54, kept by decision 130). A second Project from the same Lead
     * would read the same, so it also carries what was sold: "(Deck)".
     */
    public static function titleFor(Lead $lead, Proposal $proposal): string
    {
        $service = $lead->services()->get()->first(
            fn (Service $service): bool => (bool) $service->getRelationValue('pivot')?->getAttribute('is_primary'),
        )->name ?? $proposal->name;

        $property = $lead->property;
        $street = $property === null ? null : ($property->address_line1 === null ? $property->address : AddressKey::shortStreet($property->address_line1));
        $title = $street === null || $street === '' ? $service : $service.' — '.$street;

        if (Project::query()->where('lead_id', $lead->id)->where('title', $title)->exists()) {
            $title .= ' ('.$proposal->name.')';
        }

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

        return $project;
    }
}

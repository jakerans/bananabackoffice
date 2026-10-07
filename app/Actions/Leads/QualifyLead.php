<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class QualifyLead
{
    /**
     * What each missing requirement is called when a Lead cannot be qualified yet.
     */
    public const array REQUIREMENTS = [
        'location' => 'a location',
        'assignee' => 'someone assigned to it',
        'customer' => 'a customer',
        'contact' => 'a contact',
        'property' => 'a property',
        'service' => 'a service, with one marked as the main one',
        'project_size' => 'a project size',
    ];

    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordAuditEvent $recordAuditEvent,
        private RecordLeadStatusChange $recordLeadStatusChange,
    ) {}

    /**
     * Mark a Working Lead as Qualified: a legitimate opportunity, with the core
     * information required by the existing qualification milestone.
     *
     * It must have a location, an assignee, a customer with a contact and a
     * property, at least one service with a main one, and a project size.
     * This does not mean booked, Lead Issued, demoed, sold or Project-created.
     *
     * The first time a Lead is qualified is kept for good, whatever happens to
     * it afterwards, so it can always be counted as having been qualified.
     *
     * The caller is responsible for checking that the person may do this.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, User $actor, ?int $expectedVersion = null): void
    {
        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $actor): bool {
            if ($lead->status !== LeadStatus::Working) {
                throw ValidationException::withMessages([
                    'status' => __('Only a lead that is being worked can be qualified. This one is :status.', ['status' => $lead->status->label()]),
                ]);
            }

            $gaps = $lead->qualificationGaps();

            if ($gaps !== []) {
                throw ValidationException::withMessages([
                    'qualification' => __('This lead cannot be qualified yet. It still needs: :missing.', [
                        'missing' => implode('; ', array_map(fn (string $gap): string => self::REQUIREMENTS[$gap], $gaps)),
                    ]),
                ]);
            }

            $lead->forceFill([
                'status' => LeadStatus::Qualified,
                'status_changed_at' => now(),
                'first_qualified_at' => $lead->first_qualified_at ?? now(),
            ])->save();

            $this->recordLeadStatusChange->handle($lead, LeadStatusEvent::Qualified, LeadStatus::Working, $actor, $lead->assigned_user_id);

            $this->recordAuditEvent->handle(
                AuditAction::LeadQualified,
                $lead,
                actor: $actor,
                before: ['status' => LeadStatus::Working->value],
                after: ['status' => LeadStatus::Qualified->value],
                location: $lead->location,
            );

            return true;
        });
    }
}

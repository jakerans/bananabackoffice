<?php

namespace App\Actions\ContactCenter;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SetQueueManagerView
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Switch the manager part of the call queue on or off for one Call Center
     * Agent (decision 85).
     *
     * Switched on, the agent also gets what people who hand out Leads get
     * where the agent works: the manager summary, the timer warning, the
     * "Cadence finished, no contact" list and Put back in queue, and the Call
     * queue panel on a Lead. It changes nothing else the agent may do, and it
     * means nothing for a person who is not a Call Center Agent, so that is
     * refused.
     *
     * Only the people who manage the Contact Center settings may change it.
     * The change and its audit event are saved together or not at all, and a
     * save that changes nothing writes neither.
     *
     * @return bool Whether anything changed.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, User $agent, bool $allowed): bool
    {
        Gate::forUser($actor)->authorize(Permission::ManageContactCenterSettings->value);

        $changed = DB::transaction(function () use ($actor, $agent, $allowed): bool {
            $current = User::query()->lockForUpdate()->findOrFail($agent->id);

            if (! $current->holdsRole(Role::CallCenterAgent)) {
                throw ValidationException::withMessages([
                    'agent' => __(':name is not a Call Center Agent, so there is nothing to switch.', ['name' => $current->name]),
                ]);
            }

            if ($current->queue_manager_view === $allowed) {
                return false;
            }

            $current->forceFill(['queue_manager_view' => $allowed])->save();

            $this->recordAuditEvent->handle(
                AuditAction::StaffQueueManagerViewChanged,
                $current,
                actor: $actor,
                before: ['queue_manager_view' => ! $allowed],
                after: ['queue_manager_view' => $allowed],
            );

            return true;
        });

        $agent->refresh();

        return $changed;
    }
}

<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ColorApprovalMethod;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrderSent;
use App\Support\Projects\RoughTimeframe;
use App\Support\Projects\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Record that a Work Package's work order was sent to its subcontractor, and
 * what it said (docs/slice-13-giving-work-plan.md, step 13c; decision 151,
 * question 8). Backoffice sends nothing.
 *
 * The Project's Production Manager, or a manager there, who may see payouts.
 * The colors must be approved first (decision 141). In the customer's
 * Activity by the subcontractor's business name; never the payout.
 */
class SendWorkOrder
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Project $project, int $packageId, ?string $method, ?string $sentOn, User $actor): WorkOrderSent
    {
        return DB::transaction(function () use ($project, $packageId, $method, $sentOn, $actor): WorkOrderSent {
            $project = Project::query()->with(['location', 'colorSpecifications.lines'])->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('managePayouts', $project);

            $package = $project->workPackages()->with(['currentAward.subcontractor.locations', 'currentAward.subcontractor.complianceRecords'])->whereKey($packageId)->first()
                ?? throw ValidationException::withMessages(['workOrder' => __('This work package is no longer here. Reload the page.')]);

            $order = WorkOrder::for($project, $package)
                ?? throw ValidationException::withMessages(['workOrder' => __('This work is not given to a subcontractor.')]);

            if (! $order->canBeSent()) {
                throw ValidationException::withMessages(['workOrder' => __('It cannot be sent yet. It still needs :things.', ['things' => implode(', ', array_map(fn (string $thing): string => __($thing), $order->missing()))])]);
            }

            $how = ColorApprovalMethod::tryFrom((string) $method);
            $day = self::day($sentOn);
            $errors = [];

            if ($how === null) {
                $errors['workOrderMethod'] = __('Choose how it was sent.');
            }

            if ($day === null) {
                $errors['workOrderSentOn'] = __('Choose the day it was sent.');
            } elseif ($day->greaterThan(RoughTimeframe::today($project->location))) {
                $errors['workOrderSentOn'] = __('The day it was sent cannot be in the future.');
            }

            if ($how === null || $day === null || $errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $sent = new WorkOrderSent;
            $sent->forceFill([
                'work_package_id' => $package->id,
                'work_package_award_id' => $order->award->id,
                'method' => $how,
                'sent_on' => $day,
                'first_day' => $order->firstDay,
                'last_day' => $order->lastDay,
                'payout_cents' => $order->payoutCents,
                'deduction_cents' => $order->deductionCents,
                'deduction_basis_points' => $order->rate->basis_points ?? 0,
                'sent_by' => $actor->id,
                'sent_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectWorkOrderSent,
                $project,
                actor: $actor,
                after: [
                    'package' => $package->name,
                    'subcontractor' => $order->award->subcontractor->business_name,
                    'first_day' => $order->firstDay->toDateString(),
                    'last_day' => $order->lastDay->toDateString(),
                    'method' => $how->value,
                    'sent_on' => $day->toDateString(),
                ],
                location: $project->location,
            );

            return $sent;
        });
    }

    /**
     * A day as typed, "2026-10-14", or nothing when it is not one.
     */
    private static function day(?string $typed): ?CarbonImmutable
    {
        $typed = trim((string) $typed);
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $typed) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $typed, 'UTC') : null;

        return $day instanceof CarbonImmutable && $day->toDateString() === $typed ? $day : null;
    }
}

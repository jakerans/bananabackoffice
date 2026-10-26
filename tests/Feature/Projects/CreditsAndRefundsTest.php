<?php

use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\ReconcileJobCosts;
use App\Actions\Projects\RecordBalanceAdjustments;
use App\Actions\Projects\RecordJobCosts;
use App\Actions\Projects\RecordPayments;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use App\Support\Projects\AfterPayouts;
use App\Support\Projects\JobCosting;
use App\Support\Projects\Payments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Credits and refunds, steps 24a and 24b (docs/slice-24-refunds-credits-plan.md;
 * decision 181, questions 2 to 4; product-architecture.md, 10.5).
 *
 * It is Monday 23 November 2026 at Lakeview. "Oak St" sold for $8,450; its
 * final invoice of $8,450 went out today. Pat is its Production Manager,
 * Morgan manages Lakeview, Fran is Finance there, Sam sold it.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($project, $this->sam);
    app(TakeProject::class)->handle($project, $this->pat);
    app(RecordWelcomeCall::class)->handle($project, '2026-10-14', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($project, '2026-11-16', '2026-11-27', null, null, $this->pat);
    $package = app(ChangeWorkPackages::class)->addPackage($project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    app(ChangeWorkPackages::class)->setPayout($project, $package->id, '4250', null, $this->pat);
    $project->forceFill(['production_state' => ProjectProductionState::SignedOff])->save();

    $this->travelTo('2026-11-23 15:00:00');
    $this->project = $project->fresh();
    $this->final = app(RecordPayments::class)->recordInvoice($this->project, 'final', '1077', '8,450', '2026-11-23', $this->fran);
});

function adjust(): RecordBalanceAdjustments
{
    return app(RecordBalanceAdjustments::class);
}

test('a credit is asked for on the final invoice and approved by someone else; it lowers what is owed and the revenue', function () {
    $credit = adjust()->requestCredit($this->project, '500', 'Touch-up missed at the walkthrough.', $this->pat);

    expect(Payments::for($this->project)->balanceCents())->toBe(845000);
    expect(fn () => adjust()->approve($this->project, $credit->id, null, $this->sam))->toThrow(AuthorizationException::class);

    adjust()->approve($this->project, $credit->id, 'CM-1077', $this->fran);

    expect(Payments::for($this->project)->balanceCents())->toBe(795000)
        ->and(Payments::for($this->project)->contractCents())->toBe(795000)
        ->and(AfterPayouts::for($this->project)->contractCents)->toBe(795000)
        ->and(JobCosting::for($this->project->fresh())->marginCents())->toBe(795000 - 425000)
        ->and($credit->fresh()->quickbooks_reference)->toBe('CM-1077')
        ->and(AuditEvent::query()->where('action', AuditAction::ProjectAdjustmentApproved)->count())->toBe(1);
});

test('whoever asked never decides; a decline says why and changes nothing', function () {
    $credit = adjust()->requestCredit($this->project, '500', 'Goodwill.', $this->manager);

    expect(fn () => adjust()->approve($this->project, $credit->id, null, $this->manager))->toThrow(ValidationException::class, 'Someone other than whoever asked for it decides it.');
    expect(fn () => adjust()->decline($this->project, $credit->id, ' ', $this->fran))->toThrow(ValidationException::class, 'Say why it is declined.');

    adjust()->decline($this->project, $credit->id, 'The touch-up is booked instead.', $this->fran);

    expect(Payments::for($this->project)->balanceCents())->toBe(845000)
        ->and($credit->fresh()->declined_reason)->toBe('The touch-up is booked instead.');
    expect(fn () => adjust()->approve($this->project, $credit->id, null, $this->fran))->toThrow(ValidationException::class, 'This was decided or changed since. Reload the page.');
});

test('a credit needs the final invoice, why, and job costing not yet approved; only the Production Manager or a manager asks', function () {
    expect(fn () => adjust()->requestCredit($this->project, '500', '', $this->pat))->toThrow(ValidationException::class, 'Say why.');
    expect(fn () => adjust()->requestCredit($this->project, '500', 'Goodwill.', $this->sam))->toThrow(AuthorizationException::class);
    expect(fn () => adjust()->requestCredit($this->project, '9000', 'Too much.', $this->pat))->toThrow(ValidationException::class, 'A credit cannot be more than the contract, $8,450.');

    app(RecordJobCosts::class)->submit($this->project, $this->pat);
    app(ReconcileJobCosts::class)->approve($this->project, $this->fran);

    expect(fn () => adjust()->requestCredit($this->project, '500', 'Goodwill.', $this->pat))->toThrow(ValidationException::class, 'Finance approved this job costing. Finance reopens it before a credit.');
});

test('a refund is never more than was collected; once approved Finance records it paid, and it is owed again', function () {
    expect(fn () => adjust()->requestRefund($this->project, '100', 'Overpaid.', $this->pat))->toThrow(ValidationException::class, 'A refund cannot be more than what was collected and not yet refunded, $0.');

    app(RecordPayments::class)->recordPayment($this->project, $this->final->id, '8,450', 'ach', '2026-11-23', 'ach_1', null, $this->fran);
    $credit = adjust()->requestCredit($this->project, '500', 'Touch-up missed.', $this->pat);
    adjust()->approve($this->project, $credit->id, null, $this->manager);

    expect(Payments::for($this->project)->balanceCents())->toBe(-50000);

    $refund = adjust()->requestRefund($this->project, '500', 'Pay back the credit.', $this->pat);
    adjust()->approve($this->project, $refund->id, null, $this->fran);

    expect(Payments::for($this->project)->balanceCents())->toBe(-50000);
    expect(fn () => adjust()->recordRefundPaid($this->project, $refund->id, 'ach', '2026-11-23', 'R-1', $this->pat))->toThrow(AuthorizationException::class);

    adjust()->recordRefundPaid($this->project, $refund->id, 'ach', '2026-11-23', 'R-1', $this->fran);

    expect(Payments::for($this->project)->balanceCents())->toBe(0)
        ->and(Payments::for($this->project)->state())->toBe(Payments::PAID_IN_FULL)
        ->and(Payments::for($this->project)->refundedCents())->toBe(50000);
});

test('the job asks for a credit and Finance approves it there', function () {
    Livewire::actingAs($this->pat)->test('pages::projects.adjustments', ['project' => $this->project])
        ->call('openAsk', 'credit')
        ->set('adjustmentAmount', '250')
        ->set('adjustmentReason', 'Touch-up missed.')
        ->call('ask')
        ->assertHasNoErrors()
        ->assertSee('Waiting for approval')
        ->assertDontSeeHtml('data-test="approve-adjustment"');

    $credit = $this->project->balanceAdjustments()->sole();

    Livewire::actingAs($this->fran)->test('pages::projects.adjustments', ['project' => $this->project])
        ->call('openApprove', $credit->id)
        ->set('adjustmentReference', 'CM-1')
        ->call('approve')
        ->assertHasNoErrors()
        ->assertSee('Approved by Fran Finance');

    expect(Payments::for($this->project)->balanceCents())->toBe(820000);
});

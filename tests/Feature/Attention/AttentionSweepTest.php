<?php

use App\Actions\Attention\RunAttentionSweep;
use App\Actions\Compensation\ManagePay;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\ManageWarrantyCases;
use App\Actions\Projects\RecordBalanceAdjustments;
use App\Actions\Projects\RecordPayments;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\Role;
use App\Models\AttentionItem;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AttentionNotice;
use Illuminate\Support\Facades\Notification;

/*
 * The exception engine, step 26a (docs/slice-26-needs-attention-plan.md;
 * decision 185, questions 2, 3 and 6; product-architecture.md, 19).
 *
 * It is Friday 20 November 2026 at Lakeview. Pat runs "Oak St"; Morgan
 * manages Lakeview; Fran is its Finance; Sam sold it; Finley is Finance for
 * every Location.
 */

beforeEach(function () {
    $this->travelTo('2026-11-02 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->finley = User::factory()->withRoleEverywhere(Role::Finance)->create(['name' => 'Finley Finance']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($project, $this->sam);
    app(TakeProject::class)->handle($project, $this->pat);
    app(RecordWelcomeCall::class)->handle($project, '2026-11-02', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($project, '2026-11-09', '2026-11-20', null, null, $this->pat);
    $this->package = app(ChangeWorkPackages::class)->addPackage($project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    $this->project = $project->fresh();

    $this->travelTo('2026-11-20 15:00:00');
});

function sweep(): array
{
    return app(RunAttentionSweep::class)->handle();
}

/**
 * @return array<int, array{rule: string, user: int, severity: string}>
 */
function openItems(): array
{
    return AttentionItem::query()->whereNull('closed_at')->orderBy('id')->get()
        ->map(fn (AttentionItem $item): array => ['rule' => $item->rule, 'user' => $item->user_id, 'severity' => $item->severity])
        ->all();
}

test('work running past its last day is Urgent for its Production Manager, rings the bell once, and closes when finished', function () {
    Notification::fake();
    $this->package->forceFill(['scheduled_first_day' => '2026-11-09', 'scheduled_last_day' => '2026-11-13', 'work_started_on' => '2026-11-09'])->save();

    sweep();
    sweep();

    expect(openItems())->toBe([['rule' => 'work_running_over', 'user' => $this->pat->id, 'severity' => 'urgent']]);
    Notification::assertSentToTimes($this->pat, AttentionNotice::class, 1);

    $this->package->forceFill(['work_finished_on' => '2026-11-20'])->save();
    expect(sweep())->toBe(['opened' => 0, 'closed' => 1])
        ->and(openItems())->toBe([]);
});

test('a final balance past due is the Production Manager\'s; with Collections it is Finance\'s, and Urgent', function () {
    $record = app(RecordPayments::class);
    $record->recordInvoice($this->project, 'final', '1077', '8,450', '2026-11-05', $this->fran);

    sweep();
    expect(openItems())->toBe([['rule' => 'balance_past_due', 'user' => $this->pat->id, 'severity' => 'attention']]);

    $this->travelTo('2026-12-10 15:00:00');
    sweep();

    expect(collect(openItems())->where('rule', 'balance_past_due')->values()->all())->toEqualCanonicalizing([
        ['rule' => 'balance_past_due', 'user' => $this->fran->id, 'severity' => 'urgent'],
        ['rule' => 'balance_past_due', 'user' => $this->finley->id, 'severity' => 'urgent'],
    ]);
});

test('a warranty case nobody owns is Urgent for the Location\'s managers', function () {
    $this->project->forceFill(['production_manager_id' => null, 'production_manager_name' => null])->save();
    app(ManageWarrantyCases::class)->open($this->project->fresh(), 'Peeling.', 'The customer', $this->sam);

    sweep();

    expect(collect(openItems())->where('rule', 'warranty_unowned')->values()->all())->toBe([['rule' => 'warranty_unowned', 'user' => $this->manager->id, 'severity' => 'urgent']]);
});

test('a credit waiting two days goes to those who may decide it, never whoever asked', function () {
    app(RecordPayments::class)->recordInvoice($this->project, 'final', '1077', '8,450', '2026-11-20', $this->fran);
    app(RecordBalanceAdjustments::class)->requestCredit($this->project, '200', 'Touch-up.', $this->manager);

    sweep();
    expect(collect(openItems())->where('rule', 'adjustment_waiting'))->toBeEmpty();

    $this->travelTo('2026-11-23 15:00:00');
    sweep();

    expect(collect(openItems())->where('rule', 'adjustment_waiting')->pluck('user')->sort()->values()->all())->toBe(collect([$this->fran->id, $this->finley->id])->sort()->values()->all());
});

test('a pay period over five days and not finalized goes to those who manage pay', function () {
    $this->travelTo('2026-10-14 15:00:00');
    app(ManagePay::class)->adjust($this->sam, 'add', '100', null, 'Spiff.', $this->finley);

    $this->travelTo('2026-11-04 15:00:00');
    sweep();
    expect(collect(openItems())->where('rule', 'pay_period_open'))->toBeEmpty();

    $this->travelTo('2026-11-06 15:00:00');
    sweep();
    expect(collect(openItems())->where('rule', 'pay_period_open')->pluck('user')->all())->toBe([$this->finley->id]);
});

test('the sweep keeps one item per owner and thing, and a fixed thing reopens as a new item', function () {
    $this->package->forceFill(['scheduled_first_day' => '2026-11-09', 'scheduled_last_day' => '2026-11-13'])->save();
    sweep();
    $this->package->forceFill(['scheduled_last_day' => '2026-11-27'])->save();
    sweep();
    $this->package->forceFill(['scheduled_last_day' => '2026-11-13'])->save();
    sweep();

    expect(AttentionItem::query()->where('rule', 'work_running_over')->count())->toBe(2)
        ->and(AttentionItem::query()->where('rule', 'work_running_over')->whereNull('closed_at')->count())->toBe(1);
    expect(Project::query()->count())->toBe(1);
});

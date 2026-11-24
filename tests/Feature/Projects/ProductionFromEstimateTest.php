<?php

use App\Actions\Estimates\ChangeEstimateDiscounts;
use App\Actions\Estimates\SaveEstimateLineItem;
use App\Actions\Projects\RecordPayments;
use App\Actions\Projects\TakeProject;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\AuditAction;
use App\Enums\LineItemPricing;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Discount;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkPackageWindow;
use App\Notifications\ProjectNotice;
use App\Support\Projects\AmbassadorPackage;
use App\Support\Projects\ProductionPath;
use App\Support\Projects\WorkFromEstimate;
use App\Support\Queue\CallingHours;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Slice 39: production from the accepted estimate
 * (docs/slice-39-production-from-estimate-plan.md; decisions 235 and 237).
 * Made-up prices only: labor is $50 an hour.
 */

beforeEach(function () {
    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->lead = bookableLead($this->lakeview);
    bookAppointment($this->lead, $this->sam, $this->manager);
    $this->travelTo(now()->timezone(CallingHours::timezoneFor($this->lakeview))->addDay()->setTime(15, 30));
    runAppointment($this->lead->fresh(), $this->sam);
});

/**
 * Sell the Lead an estimate of 46 hours of work, $2,300, on its card.
 *
 * @param  array{name: string, kind: string, value: string}|null  $discount  A discount to give the estimate first.
 */
function sellFromEstimate(?array $discount = null): Project
{
    $estimate = estimateFor(test()->lead->fresh(), test()->sam, ['Whole interior' => '100']);
    $option = $estimate->options()->sole();
    $option->lineItems()->delete();
    app(SaveEstimateLineItem::class)->handle($estimate, $option, null, ['name' => 'Walls and trim', 'pricing' => LineItemPricing::Hours->value, 'hours' => '46'], test()->sam);

    if ($discount !== null) {
        $named = Discount::factory()->create(['price_book_id' => $estimate->price_book_id, ...$discount]);
        app(ChangeEstimateDiscounts::class)->handle($estimate->refresh(), [$named->id], null, test()->sam);
    }

    $proposal = presentEstimate($estimate->refresh(), test()->sam);
    app(RecordProposalResult::class)->handle(test()->lead->fresh(), $proposal, 'sold', ['optionId' => $option->id], test()->sam);

    return Project::query()->where('lead_id', test()->lead->id)->sole();
}

/**
 * @return list<array<string, mixed>>
 */
function readyNotices(User $user): array
{
    return $user->fresh()->notifications->map->data->filter(fn (array $data): bool => $data['kind'] === ProjectNotice::READY_FOR_PRODUCTION)->values()->all();
}

test('a sold job waits out of Production\'s lists until its deposit is paid, and Production is told then', function () {
    $project = sellFromEstimate();

    expect($project->awaiting_deposit)->toBeTrue();

    // The salesperson hands it over meanwhile; Production is not told yet.
    completeHandoff($project, $this->sam);

    expect(readyNotices($this->pat))->toBeEmpty();

    Livewire::actingAs($this->pat)->test('pages::production.workspace')
        ->assertDontSee($project->title)
        ->assertDontSeeHtml('data-test="workspace-awaiting-deposit"');

    // A manager sees what waits, and why.
    Livewire::actingAs($this->manager)->test('pages::production.workspace')
        ->assertSeeHtml('data-test="workspace-awaiting-deposit"')
        ->assertSee($project->title)
        ->assertSee('$1,150 deposit asked for');

    Livewire::actingAs($this->manager)->test('pages::projects.show', ['project' => $project])
        ->assertSeeHtml('data-test="project-awaiting-deposit"');

    $record = app(RecordPayments::class);
    $today = now()->timezone(CallingHours::timezoneFor($this->lakeview))->toDateString();
    $invoice = $record->recordInvoice($project, 'deposit', '1001', '1,150', $today, $this->manager);
    $record->recordPayment($project, $invoice->id, '1,150', 'card', $today, 'ch_1', null, $this->manager);

    expect($project->refresh()->awaiting_deposit)->toBeFalse()
        ->and(readyNotices($this->pat))->toHaveCount(1)
        ->and(AuditEvent::query()->where('action', AuditAction::ProjectReleasedToProduction)->sole()->values_after)->toEqual(['because' => 'The deposit is paid']);

    Livewire::actingAs($this->pat)->test('pages::production.workspace')->assertSee($project->title);

    // Released once, it stays released: a reversal later does not take the job away.
    $payment = $project->payments()->sole();
    $record->reversePayment($project, $payment->id, 'The card was declined.', User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create());

    expect($project->refresh()->awaiting_deposit)->toBeFalse();
});

test('a job handed over after its deposit is paid is announced at the handoff, as before', function () {
    $project = sellFromEstimate();
    app(RecordPayments::class)->changeDeposit($project, '0', 'A returning customer.', $this->manager);

    // Waived by a manager, it goes through to Production.
    expect($project->refresh()->awaiting_deposit)->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::ProjectReleasedToProduction)->sole()->values_after)->toEqual(['because' => 'No deposit is asked for'])
        ->and(readyNotices($this->pat))->toBeEmpty();

    completeHandoff($project, $this->sam);

    expect(readyNotices($this->pat))->toHaveCount(1);
});

test('a job with no deposit to collect is never held', function () {
    $this->lakeview->forceFill(['deposit_percent' => 0])->save();

    expect(sellFromEstimate()->awaiting_deposit)->toBeFalse();
});

test('an estimate sold with a Brand Ambassador discount carries its package into the job', function (array $discount, ?string $tier) {
    $project = sellFromEstimate($discount);

    expect($project->ambassador_tier)->toBe($tier);

    if ($tier !== null) {
        // The price sold is after the discount; the retail price is worked back from it.
        expect($project->ambassador_discount_percent)->toBe(AmbassadorPackage::TIERS[$tier]['discount'])
            ->and($project->ambassador_items)->toBe(AmbassadorPackage::TIERS[$tier]['items'])
            ->and($project->retail_cents)->toBe(230000);
    }
})->with([
    'named for its tier' => [['name' => 'Brand Ambassador Gold', 'kind' => 'percentage', 'value' => '25'], 'gold'],
    'told from its percent' => [['name' => 'Brand Ambassador', 'kind' => 'percentage', 'value' => '15'], 'silver'],
    'no tier to tell' => [['name' => 'Brand Ambassador', 'kind' => 'percentage', 'value' => '12'], null],
    'another discount' => [['name' => 'Paying by check', 'kind' => 'percentage', 'value' => '10'], null],
]);

test('the first work package starts from the signed estimate: its name, its work, and the days its hours take', function () {
    $project = sellFromEstimate();
    completeHandoff($project, $this->sam);

    // Not Production's to take while the deposit is owed.
    expect($this->pat->can('take', $project->refresh()))->toBeFalse();

    app(RecordPayments::class)->changeDeposit($project, '0', 'A returning customer.', $this->manager);
    app(TakeProject::class)->handle($project->refresh(), $this->pat);
    // The customer called and a rough timeframe set: ready to schedule.
    $project->forceFill(['welcome_call_on' => now()->toDateString(), 'timeframe_earliest' => '2026-12-01', 'timeframe_latest' => '2026-12-15'])->save();

    $from = WorkFromEstimate::for($project);

    // 46 hours for a crew of 2 at 8 hours a day is 2.9 days: 3 working days.
    expect($from)->hours->toBe(46.0)->crew->toBe(2)->workingDays->toBe(3)
        ->and($from->scope)->toContain('Walls and trim')
        ->and(WorkFromEstimate::daysFor(4, 2))->toBe(1)
        ->and(WorkFromEstimate::daysFor(80, 2))->toBe(5);

    Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $project])
        ->call('openAdd')
        ->assertSet('package.name', 'Whole interior')
        ->assertSet('package.workingDays', '3')
        ->assertSet('package.minimumCrew', '2')
        ->assertSeeHtml('data-test="work-package-from-estimate"')
        ->assertSee('46 hours of work, about 3 working days for a crew of 2')
        ->call('savePackage')
        ->assertHasNoErrors();

    $package = $project->workPackages()->sole();

    expect($package)->name->toBe('Whole interior')->working_days->toBe(3)->minimum_crew->toBe(2)
        // What the salesperson handed over says what the work is, once there is a handoff.
        ->and($package->scope)->toBe('All four sides, trim and both doors. Two coats.');

    // The first day chosen, the last follows: three working days from a Friday end on the Tuesday.
    Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $project])
        ->call('openWindow', $package->id)
        ->set('windowFirstDay', '2026-12-04')
        ->assertSet('windowLastDay', '2026-12-08');
});

test('a job not sold from an estimate starts its work package as before', function () {
    $project = soldProject($this->lakeview, $this->sam, ['production_manager_id' => $this->pat->id, 'production_manager_name' => $this->pat->name]);

    expect(WorkFromEstimate::for($project))->toBeNull();

    Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $project])
        ->call('openAdd')
        ->assertSet('package.workingDays', '')
        ->assertDontSeeHtml('data-test="work-package-from-estimate"');
});

test('work ends on the working day its days run out, Monday to Friday', function (string $first, int $days, string $last) {
    expect(WorkPackageWindow::lastDayFor(CarbonImmutable::parse($first), $days)->toDateString())->toBe($last);
})->with([
    'a week from Monday' => ['2026-11-16', 5, '2026-11-20'],
    'over a weekend' => ['2026-11-18', 5, '2026-11-24'],
    'one day' => ['2026-11-18', 1, '2026-11-18'],
    'started on a Saturday' => ['2026-11-21', 2, '2026-11-24'],
    'two weeks' => ['2026-11-16', 10, '2026-11-27'],
]);

test('the job page shows the whole way through Production, and what is next', function () {
    $project = sellFromEstimate();

    $path = ProductionPath::for($project);

    expect(array_column($path->steps, 'key'))->toBe(['deposit', 'handoff', 'production_manager', 'welcome_call', 'timeframe', 'colors', 'work_package', 'given_out', 'dates', 'started', 'finished', 'signed_off', 'final_invoice', 'paid'])
        ->and($path->next()['key'])->toBe('deposit')
        ->and($path->done())->toBe(0);

    app(RecordPayments::class)->changeDeposit($project, '0', 'A returning customer.', $this->manager);
    completeHandoff($project->refresh(), $this->sam);

    $path = ProductionPath::for($project->refresh());

    expect($path->next()['key'])->toBe('production_manager')
        ->and($path->done())->toBe(2)
        // Colors never hold a job up, so they are never what is next.
        ->and($path->needed())->toBe(13);

    Livewire::actingAs($this->manager)->test('pages::projects.show', ['project' => $project])
        ->assertSeeHtml('data-test="project-path"')
        ->assertSee('Next: Production Manager')
        ->assertSee('2 of 13 steps done');
});

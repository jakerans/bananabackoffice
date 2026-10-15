<?php

use App\Actions\Projects\AwardWorkPackage;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Actions\Subcontractors\ChangeSubcontractor;
use App\Actions\Subcontractors\RecordCompliance;
use App\Enums\AuditAction;
use App\Enums\ColorApprovalMethod;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\ComplianceRequirement;
use App\Models\Location;
use App\Models\Subcontractor;
use App\Models\User;
use App\Models\WorkPackageAward;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The award, step 13b (docs/slice-13-giving-work-plan.md; product-architecture.md,
 * 6.6, 7.4 and 7.8; decision 151, questions 2 to 6).
 *
 * It is Wednesday 14 October 2026 at Lakeview. "Oak St" is ready to schedule;
 * its whole exterior is one package of five working days with a payout of
 * $4,250, and the customer was offered 16 to 20 and 23 to 27 November.
 * Sandia Brush Co., a crew of four, is Active and compliant there.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->pam = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pam Production']);
    $this->finance = User::factory()->withRoleEverywhere(Role::Finance)->create(['name' => 'Fran Finance']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-14', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-16', '2026-11-27', null, null, $this->pat);
    $this->package = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    app(ChangeWorkPackages::class)->setPayout($this->project, $this->package->id, '4250', null, $this->pat);
    $this->first = app(ChangeWorkPackages::class)->proposeWindow($this->project, $this->package->id, '2026-11-16', '2026-11-20', $this->pat);
    $this->second = app(ChangeWorkPackages::class)->proposeWindow($this->project, $this->package->id, '2026-11-23', '2026-11-27', $this->pat);
    $this->project = $this->project->fresh();

    $this->sandia = awardReadySubcontractor($this, 'Sandia Brush Co.', 4);
});

/**
 * An Active subcontractor at Lakeview with every base item on file.
 */
function awardReadySubcontractor($test, string $name, ?int $crew = null): Subcontractor
{
    $subcontractor = app(ChangeSubcontractor::class)->add([
        'businessName' => $name, 'contactName' => 'Rosa Alvarez', 'phone' => '505-555-0142', 'crewSize' => (string) $crew,
        'locationIds' => [(string) $test->lakeview->id],
    ], $test->pat);
    app(ChangeSubcontractor::class)->moveTo($subcontractor, 'active', null, $test->pat);

    foreach (ComplianceRequirement::query()->whereNotNull('base_key')->get() as $requirement) {
        app(RecordCompliance::class)->handle($subcontractor, $requirement->id, '2026-10-01', $requirement->expires ? '2027-09-30' : null, false, null, $test->pat);
    }

    return $subcontractor->fresh();
}

function awarded($test, ?Subcontractor $subcontractor = null, ?int $windowId = -1, ?User $by = null): WorkPackageAward
{
    return app(AwardWorkPackage::class)->award(
        $test->project,
        $test->package->id,
        ($subcontractor ?? $test->sandia)->id,
        $windowId === -1 ? $test->first->id : $windowId,
        'Rosa Alvarez',
        'phone',
        '2026-10-14',
        $by ?? $test->pat,
    );
}

/*
|--------------------------------------------------------------------------
| Giving the work
|--------------------------------------------------------------------------
*/

test('the work is given to a subcontractor for one of the windows offered, with how they accepted', function () {
    $award = awarded($this);

    expect($award->only(['work_package_id', 'subcontractor_id', 'payout_cents', 'work_package_window_id', 'accepted_by', 'recorded_by', 'recorded_by_name', 'ended_at']))->toBe([
        'work_package_id' => $this->package->id,
        'subcontractor_id' => $this->sandia->id,
        'payout_cents' => 425000,
        'work_package_window_id' => $this->first->id,
        'accepted_by' => 'Rosa Alvarez',
        'recorded_by' => $this->pat->id,
        'recorded_by_name' => 'Pat Production',
        'ended_at' => null,
    ]);
    expect([$award->first_day->toDateString(), $award->last_day->toDateString(), $award->accepted_on->toDateString()])->toBe(['2026-11-16', '2026-11-20', '2026-10-14']);
    expect($award->acceptance_method)->toBe(ColorApprovalMethod::Phone);
    expect($this->package->fresh()->currentAward->is($award))->toBeTrue();

    // In the customer's Activity by business name; the payout is in neither the event nor the Activity.
    $event = AuditEvent::query()->where('action', AuditAction::ProjectWorkAwarded)->sole();

    expect($event->values_after)->toEqual([
        'package' => 'Whole exterior', 'subcontractor' => 'Sandia Brush Co.', 'first_day' => '2026-11-16', 'last_day' => '2026-11-20',
        'accepted_by' => 'Rosa Alvarez', 'method' => 'phone', 'accepted_on' => '2026-10-14',
    ]);
    expect(CustomerActivity::actions())->toContain(AuditAction::ProjectWorkAwarded);
});

test('work whose dates the customer already confirmed is given for those dates', function () {
    app(ChangeWorkPackages::class)->confirmWindow($this->project, $this->second->id, 'Pat Customer', 'phone', '2026-10-14', $this->pat);

    $award = awarded($this, windowId: null);

    expect([$award->first_day->toDateString(), $award->last_day->toDateString(), $award->work_package_window_id])->toBe(['2026-11-23', '2026-11-27', null]);
});

test('the customer then confirms the days the subcontractor took, and no others', function () {
    awarded($this);

    expect(fn () => app(ChangeWorkPackages::class)->confirmWindow($this->project, $this->second->id, 'Pat Customer', 'phone', '2026-10-14', $this->pat))
        ->toThrow(ValidationException::class, 'Sandia Brush Co. took Mon, Nov 16 – Fri, Nov 20, 2026. Confirm those dates with the customer, or take the award back first.');

    app(ChangeWorkPackages::class)->confirmWindow($this->project, $this->first->id, 'Pat Customer', 'phone', '2026-10-14', $this->pat);

    expect($this->package->fresh()->isScheduled())->toBeTrue();

    // The window they took cannot be withdrawn, and the work cannot be removed.
    expect(fn () => app(ChangeWorkPackages::class)->withdrawWindow($this->project, $this->first->id, $this->pat))->toThrow(ValidationException::class);
});

test('the work cannot be given out without a payout, twice, or before dates are offered', function () {
    $other = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Deck', 'scope' => 'Deck.', 'workingDays' => '1', 'minimumCrew' => '1'], $this->pat);

    expect(fn () => app(AwardWorkPackage::class)->award($this->project, $other->id, $this->sandia->id, null, 'Rosa', 'phone', '2026-10-14', $this->pat))
        ->toThrow(ValidationException::class, 'Set the payout before giving the work out.');

    app(ChangeWorkPackages::class)->setPayout($this->project, $other->id, '500', null, $this->pat);

    expect(fn () => app(AwardWorkPackage::class)->award($this->project, $other->id, $this->sandia->id, null, 'Rosa', 'phone', '2026-10-14', $this->pat))
        ->toThrow(ValidationException::class, 'Offer dates to the customer first.');

    awarded($this);

    expect(fn () => awarded($this))->toThrow(ValidationException::class, 'Sandia Brush Co. has this work. Take it back first to give it to someone else.');
    expect(fn () => app(ChangeWorkPackages::class)->removePackage($this->project, $this->package->id, $this->pat))
        ->toThrow(ValidationException::class, 'This work is given to a subcontractor. Take the award back before removing it.');
});

test('the work is given only to an Active, compliant subcontractor at the job\'s Location', function () {
    $benched = awardReadySubcontractor($this, 'Benched Brushes');
    app(ChangeSubcontractor::class)->moveTo($benched, 'benched', 'No shows.', $this->pat);

    $pending = app(ChangeSubcontractor::class)->add(['businessName' => 'Pending Painters', 'contactName' => 'Lee', 'email' => 'lee@example.com', 'locationIds' => [(string) $this->lakeview->id]], $this->pat);
    app(ChangeSubcontractor::class)->moveTo($pending, 'active', null, $this->pat);

    $elsewhere = app(ChangeSubcontractor::class)->add(['businessName' => 'Harbor Hues', 'contactName' => 'Hal', 'email' => 'hal@example.com', 'locationIds' => [(string) $this->harbor->id]], $this->owner);

    expect(AwardWorkPackage::candidates($this->project)->pluck('business_name')->all())->toBe(['Sandia Brush Co.']);

    expect(fn () => awarded($this, $benched->fresh()))->toThrow(ValidationException::class, 'Benched Brushes is benched, not Active.');
    expect(fn () => awarded($this, $pending->fresh()))->toThrow(ValidationException::class, 'Pending Painters is pending compliance: W-9, General liability insurance');
    expect(fn () => awarded($this, $elsewhere->fresh()))->toThrow(ValidationException::class, 'Choose a subcontractor who works at Lakeview.');
    expect(WorkPackageAward::query()->count())->toBe(0);
});

test('an acceptance that is not complete is refused at each field', function (array $with, string $field) {
    $values = ['by' => 'Rosa Alvarez', 'method' => 'phone', 'on' => '2026-10-14', 'window' => $this->first->id, ...$with];

    try {
        app(AwardWorkPackage::class)->award($this->project, $this->package->id, $this->sandia->id, $values['window'], $values['by'], $values['method'], $values['on'], $this->pat);
        $this->fail('It was not refused.');
    } catch (ValidationException $refused) {
        expect(array_keys($refused->errors()))->toBe([$field]);
    }

    expect(WorkPackageAward::query()->count())->toBe(0);
})->with([
    'nobody accepted' => [['by' => ''], 'acceptedBy'],
    'no way given' => [['method' => ''], 'acceptanceMethod'],
    'a day to come' => [['on' => '2026-10-15'], 'acceptedOn'],
    'no dates chosen' => [['window' => null], 'awardWindowId'],
]);

/*
|--------------------------------------------------------------------------
| Changing the payout, and taking the work back
|--------------------------------------------------------------------------
*/

test('a payout changed after the award needs a reason, and their acceptance is recorded again as a new award', function () {
    $first = awarded($this);

    expect(fn () => app(ChangeWorkPackages::class)->setPayout($this->project, $this->package->id, '4600', '', $this->pat))
        ->toThrow(ValidationException::class, 'This work is given to a subcontractor. Say why the payout changes.');

    app(ChangeWorkPackages::class)->setPayout($this->project, $this->package->id, '4600', 'Extra trim on the garage.', $this->pat);

    $again = app(AwardWorkPackage::class)->acceptAgain($this->project, $this->package->id, 'Rosa Alvarez', 'text', '2026-10-14', $this->pat);

    expect($first->fresh()->only(['ended_cause', 'payout_cents']))->toBe(['ended_cause' => WorkPackageAward::ENDED_PAYOUT_CHANGED, 'payout_cents' => 425000]);
    expect($again->only(['subcontractor_id', 'payout_cents', 'work_package_window_id']))->toBe(['subcontractor_id' => $this->sandia->id, 'payout_cents' => 460000, 'work_package_window_id' => $this->first->id]);
    expect($this->package->fresh()->currentAward->is($again))->toBeTrue();
    expect(AuditEvent::query()->where('action', AuditAction::ProjectAwardAcceptedAgain)->count())->toBe(1);

    expect(fn () => app(AwardWorkPackage::class)->acceptAgain($this->project, $this->package->id, 'Rosa Alvarez', 'text', '2026-10-14', $this->pat))
        ->toThrow(ValidationException::class, 'They accepted the payout it has now. Nothing needs recording.');
});

test('taking the work back needs whose doing it was and why, keeps the award, and frees the work', function () {
    $award = awarded($this);

    expect(fn () => app(AwardWorkPackage::class)->takeBack($this->project, $this->package->id, 'nobody', 'Why.', $this->pat))->toThrow(ValidationException::class, 'Choose whose doing it was.');
    expect(fn () => app(AwardWorkPackage::class)->takeBack($this->project, $this->package->id, WorkPackageAward::ENDED_SUBCONTRACTOR_WITHDREW, ' ', $this->pat))->toThrow(ValidationException::class, 'Say why.');

    app(AwardWorkPackage::class)->takeBack($this->project, $this->package->id, WorkPackageAward::ENDED_SUBCONTRACTOR_WITHDREW, 'Took a bigger job.', $this->pat);

    expect($award->fresh()->only(['ended_cause', 'ended_reason', 'ended_by', 'ended_by_name']))->toBe([
        'ended_cause' => 'subcontractor_withdrew', 'ended_reason' => 'Took a bigger job.', 'ended_by' => $this->pat->id, 'ended_by_name' => 'Pat Production',
    ]);
    expect($this->package->fresh()->currentAward)->toBeNull();

    $event = AuditEvent::query()->where('action', AuditAction::ProjectAwardEnded)->sole();

    expect($event->reason)->toBe('Took a bigger job.');
    expect($event->values_after)->toEqual(['cause' => 'subcontractor_withdrew']);

    // It can be given again.
    expect(awarded($this, awardReadySubcontractor($this, 'Mesa Coats'))->exists)->toBeTrue();
});

test('an award is changed only to end it, once, and never deleted', function () {
    $award = awarded($this);

    expect(fn () => $award->forceFill(['payout_cents' => 1])->save())->toThrow(LogicException::class);
    expect(fn () => $award->fresh()->delete())->toThrow(LogicException::class);

    app(AwardWorkPackage::class)->takeBack($this->project, $this->package->id, WorkPackageAward::ENDED_CUSTOMER_OR_BANANA, 'Customer moved the job.', $this->pat);

    expect(fn () => $award->fresh()->forceFill(['ended_reason' => 'Changed'])->save())->toThrow(LogicException::class);
});

test('who may give the work out', function (Closure $person, bool $may) {
    $person = $person($this);

    if ($may) {
        expect(awarded($this, by: $person)->exists)->toBeTrue();

        return;
    }

    expect(fn () => awarded($this, by: $person))->toThrow(AuthorizationException::class);
    expect(WorkPackageAward::query()->count())->toBe(0);
})->with([
    'its Production Manager' => [fn ($test) => $test->pat, true],
    'a Location Manager there' => [fn ($test) => $test->manager, true],
    'the Owner' => [fn ($test) => $test->owner, true],
    'Finance' => [fn ($test) => $test->finance, false],
    'another Production Manager there' => [fn ($test) => $test->pam, false],
    'the salesperson who sold it' => [fn ($test) => $test->sam, false],
]);

/*
|--------------------------------------------------------------------------
| Where people see it
|--------------------------------------------------------------------------
*/

test('the production manager gives the work from the page, with a warning for a small crew', function () {
    $small = awardReadySubcontractor($this, 'Mesa Coats', 2);

    Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $this->project])
        ->assertSeeHtml('data-test="give-work"')
        ->call('openAward', $this->package->id)
        ->assertSee('Whole exterior, at $4,250.')
        ->set('awardSubcontractorId', (string) $small->id)
        ->assertSet('acceptedBy', 'Rosa Alvarez')
        ->assertSee('Their crew of 2 is smaller than the 3 this work needs.')
        ->set('awardSubcontractorId', (string) $this->sandia->id)
        ->assertDontSeeHtml('data-test="award-crew-warning"')
        ->set('awardWindowId', (string) $this->first->id)
        ->call('saveAward')
        ->assertHasErrors(['acceptanceMethod'])
        ->set('acceptanceMethod', 'phone')
        ->call('saveAward')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="work-award"')
        ->assertSee('Sandia Brush Co.')
        ->assertSee('at $4,250')
        ->call('openTakeBack', $this->package->id)
        ->call('saveTakeBack')
        ->assertHasErrors(['awardEndCause'])
        ->set('awardEndCause', WorkPackageAward::ENDED_CUSTOMER_OR_BANANA)
        ->set('awardEndReason', 'Customer moved the job.')
        ->call('saveTakeBack')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="work-award-history"')
        ->assertSee('Was given to Sandia Brush Co.')
        ->assertSee('the customer or Banana asked');
});

test('everyone who sees the job sees who has the work; only those allowed see what it pays', function () {
    awarded($this);
    app(ChangeWorkPackages::class)->confirmWindow($this->project, $this->first->id, 'Pat Customer', 'phone', '2026-10-14', $this->pat);

    Livewire::actingAs($this->sam)->test('pages::projects.work', ['project' => $this->project->fresh()])
        ->assertSee('Sandia Brush Co.')
        ->assertDontSee('$4,250')
        ->assertDontSeeHtml('data-test="work-take-back"')
        ->call('openAward', $this->package->id)
        ->assertForbidden();

    Livewire::actingAs($this->finance)->test('pages::projects.work', ['project' => $this->project->fresh()])
        ->assertSee('at $4,250')
        ->assertDontSeeHtml('data-test="work-take-back"');

    Livewire::actingAs($this->sam)->test('pages::projects.show', ['project' => $this->project->fresh()])
        ->assertSee('Work given to a subcontractor: Whole exterior to Sandia Brush Co.');

    $schedule = Livewire::actingAs($this->manager)->withQueryParams(['from' => '2026-11-16'])->test('pages::production.schedule')->html();

    expect($schedule)->toContain('Crew: Sandia Brush Co.');
    expect(Livewire::actingAs($this->manager)->test('pages::production.workspace')->html())->toContain('Crew: Sandia Brush Co.');
});

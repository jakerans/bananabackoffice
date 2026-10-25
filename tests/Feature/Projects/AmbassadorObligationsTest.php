<?php

use App\Actions\Projects\HandleAmbassadorObligation;
use App\Actions\Projects\ReconcileJobCosts;
use App\Actions\Projects\RecordAmbassadorPackage;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\ProjectAmbassadorObligation;
use App\Models\User;
use App\Notifications\ProjectNotice;
use App\Support\Projects\JobCosting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * What the customer promised, step 21b (docs/slice-21-brand-ambassador-plan.md;
 * decision 175; product-architecture.md, 6.10).
 *
 * It is Wednesday 14 October 2026 at Lakeview. Sam sold "Oak St" on the
 * Silver package; Pat is its Production Manager; Morgan manages Lakeview.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    app(RecordAmbassadorPackage::class)->correct($this->project, ['ambassadorTier' => 'silver'], null, $this->sam);
    completeHandoff($this->project->fresh(), $this->sam);
    app(TakeProject::class)->handle($this->project->fresh(), $this->pat);
    $this->project = $this->project->fresh();
});

function promise($test, string $item): ProjectAmbassadorObligation
{
    return ProjectAmbassadorObligation::query()->where('project_id', $test->project->id)->where('item', $item)->sole();
}

/**
 * @param  array<string, mixed>  $input
 */
function markPromise($test, string $item, string $how, array $input = [], ?User $by = null): ProjectAmbassadorObligation
{
    return app(HandleAmbassadorObligation::class)->handle($test->project, promise($test, $item)->id, $how, $input, $by ?? $test->pat);
}

test('each item of the package is listed to be handled, and a correction keeps what was handled', function () {
    expect($this->project->ambassadorObligations()->pluck('item')->all())->toBe(['yard_sign', 'content_permission', 'paper_survey', 'facebook_review', 'google_review', 'reference'])
        ->and($this->project->ambassadorObligations()->where('state', 'open')->count())->toBe(6);

    markPromise($this, 'google_review', 'completed');
    app(RecordAmbassadorPackage::class)->correct($this->project, ['ambassadorTier' => 'bronze'], 'The customer dropped the reviews.', $this->manager);

    expect($this->project->ambassadorObligations()->pluck('item')->all())->toBe(['yard_sign', 'content_permission', 'paper_survey', 'google_review']);
});

test('an item is completed by whoever did it, partial with a note, deferred to a later day, or waived with why', function () {
    expect(markPromise($this, 'reference', 'completed', ['doneBy' => 'Riley, the crew lead'])->done_by_name)->toBe('Riley, the crew lead');

    expect(fn () => markPromise($this, 'facebook_review', 'partial'))->toThrow(ValidationException::class, 'Say what is done and what is not.');
    expect(markPromise($this, 'facebook_review', 'partial', ['note' => 'Posted, no photos yet.'])->state)->toBe('partial');

    expect(fn () => markPromise($this, 'paper_survey', 'deferred', ['day' => '2026-10-14']))->toThrow(ValidationException::class, 'Defer it to a day after today.');
    expect(markPromise($this, 'paper_survey', 'deferred', ['day' => '2026-11-30'])->deferred_until->toDateString())->toBe('2026-11-30');

    expect(fn () => markPromise($this, 'content_permission', 'waived'))->toThrow(ValidationException::class, 'Say why it is waived.');
    markPromise($this, 'content_permission', 'waived', ['note' => 'The customer asked us not to post the house.']);

    expect(AuditEvent::query()->where('action', AuditAction::ProjectAmbassadorObligationHandled)->count())->toBe(4);
});

test('the yard sign goes up on a day and is due down 30 days later', function () {
    expect(fn () => markPromise($this, 'yard_sign', 'sign_up', ['day' => '2026-10-15']))->toThrow(ValidationException::class, 'The day it went up cannot be in the future.');
    expect(fn () => markPromise($this, 'reference', 'sign_up', ['day' => '2026-10-14']))->toThrow(ValidationException::class, 'Only the yard sign goes up.');

    $sign = markPromise($this, 'yard_sign', 'sign_up', ['day' => '2026-10-12']);

    expect($sign->state)->toBe('deferred')
        ->and($sign->sign_up_on->toDateString())->toBe('2026-10-12')
        ->and($sign->deferred_until->toDateString())->toBe('2026-11-11');
});

test('only the Production Manager or a manager handles them', function () {
    markPromise($this, 'reference', 'completed', by: $this->manager);

    expect(fn () => markPromise($this, 'yard_sign', 'completed', by: $this->sam))->toThrow(AuthorizationException::class);
});

test('the financial close waits until every item is handled; a deferred one may stay open', function () {
    $costing = JobCosting::for($this->project);

    expect(ReconcileJobCosts::whatHoldsTheClose($this->project, $costing))->toContain('every Brand Ambassador item handled');

    foreach (['content_permission', 'paper_survey', 'facebook_review', 'google_review', 'reference'] as $item) {
        markPromise($this, $item, 'completed');
    }
    markPromise($this, 'yard_sign', 'sign_up', ['day' => '2026-10-14']);

    expect(ReconcileJobCosts::whatHoldsTheClose($this->project, $costing))->not->toContain('every Brand Ambassador item handled');
});

test('the Production Manager is reminded once on the day an item comes due', function () {
    Notification::fake();
    markPromise($this, 'yard_sign', 'sign_up', ['day' => '2026-10-14']);

    $this->travelTo('2026-11-12 12:00:00');
    $this->artisan('backoffice:ambassador-reminders')->assertSuccessful();

    $this->travelTo('2026-11-13 12:00:00');
    $this->artisan('backoffice:ambassador-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->pat, ProjectNotice::class, 1);
    Notification::assertSentTo($this->pat, ProjectNotice::class, fn (ProjectNotice $notice): bool => $notice->kind === ProjectNotice::AMBASSADOR_DUE && $notice->name === 'Yard sign, 30 days');
});

test('the job lists the items and the Production Manager updates one there; Production > Workspace lists what is due', function () {
    Livewire::actingAs($this->pat)->test('pages::projects.ambassador', ['project' => $this->project])
        ->assertSeeInOrder(['What the customer promised', 'Yard sign, 30 days', 'Not handled yet.'])
        ->call('openObligation', promise($this, 'reference')->id)
        ->set('obligationHow', 'completed')
        ->set('obligationDoneBy', 'Riley')
        ->call('saveObligation')
        ->assertHasNoErrors()
        ->assertSee('Completed by Riley.');

    Livewire::actingAs($this->sam)->test('pages::projects.ambassador', ['project' => $this->project])->assertDontSeeHtml('data-test="handle-ambassador-obligation"');

    $this->project->forceFill(['production_state' => ProjectProductionState::SignedOff])->save();

    Livewire::actingAs($this->pat)->test('pages::production.workspace')
        ->assertSeeInOrder(['Brand Ambassador items due', 'Whole exterior — 1 Oak St', 'Yard sign, 30 days:', 'not handled yet']);
});

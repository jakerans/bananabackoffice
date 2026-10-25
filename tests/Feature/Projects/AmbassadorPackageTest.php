<?php

use App\Actions\Projects\RecordAmbassadorPackage;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The Brand Ambassador package on the sale, step 21a
 * (docs/slice-21-brand-ambassador-plan.md; decision 175;
 * product-architecture.md, 6.10).
 *
 * The appointment was on Thursday 8 October 2026 with Sam Sales, who marked
 * it run and added "Whole exterior", $8,450. Morgan manages Lakeview.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    $this->lead = bookableLead($this->lakeview);
    bookAppointment($this->lead, $this->sam, $this->manager);

    $this->travelTo('2026-10-08 19:00:00');
    runAppointment($this->lead->fresh(), $this->sam);
    $this->proposal = addProposal($this->lead->fresh(), $this->sam);
    $this->lead = $this->lead->fresh();
});

/**
 * @param  array<string, mixed>  $package
 */
function soldWith($test, array $package): Project
{
    app(RecordProposalResult::class)->handle($test->lead, $test->proposal, 'sold', $package, $test->sam);

    return Project::query()->where('proposal_id', $test->proposal->id)->sole();
}

test('the sale keeps its package, the discount and the retail price before it', function () {
    $project = soldWith($this, ['ambassadorTier' => 'gold']);

    expect($project->ambassador_tier)->toBe('gold')
        ->and($project->ambassador_discount_percent)->toBe(25)
        ->and($project->ambassador_items)->toHaveCount(8)
        ->and($project->ambassador_max_discount_percent)->toBe(25)
        ->and($project->retail_cents)->toBe(1126667)
        ->and($project->ambassadorPackage()?->label())->toBe('Gold, 25%')
        ->and(AuditEvent::query()->where('action', AuditAction::ProjectAmbassadorPackageRecorded)->count())->toBe(1);
});

test('no package keeps the price sold as the retail price', function () {
    $project = soldWith($this, ['ambassadorTier' => 'none']);

    expect($project->ambassador_tier)->toBe('none')->and($project->retailCents())->toBe(845000);
});

test('a custom package needs its discount, its items and why, and tells the managers', function () {
    Notification::fake();

    expect(fn () => soldWith($this, ['ambassadorTier' => 'custom', 'ambassadorDiscount' => '30', 'ambassadorItems' => ['yard_sign'], 'ambassadorReason' => 'Price.']))
        ->toThrow(ValidationException::class, 'Give the discount as a whole percent from 1 to 25.');
    expect(fn () => soldWith($this, ['ambassadorTier' => 'custom', 'ambassadorDiscount' => '12', 'ambassadorItems' => [], 'ambassadorReason' => 'Price.']))
        ->toThrow(ValidationException::class, 'Choose what the customer promises.');
    expect(fn () => soldWith($this, ['ambassadorTier' => 'custom', 'ambassadorDiscount' => '12', 'ambassadorItems' => ['yard_sign'], 'ambassadorReason' => ' ']))
        ->toThrow(ValidationException::class, 'Say why the package is custom.');
    expect(Project::query()->count())->toBe(0);

    $project = soldWith($this, ['ambassadorTier' => 'custom', 'ambassadorDiscount' => '12', 'ambassadorItems' => ['google_review', 'yard_sign'], 'ambassadorReason' => 'Only the yard sign and a review stood in the way.']);

    expect($project->ambassadorPackage()?->label())->toBe('Custom, 12%')
        ->and($project->ambassador_items)->toBe(['yard_sign', 'google_review']);
    Notification::assertSentTo($this->manager, ProjectNotice::class, fn (ProjectNotice $notice): bool => $notice->kind === ProjectNotice::AMBASSADOR_CUSTOM);
});

test('a caller that does not ask leaves the package unrecorded', function () {
    expect(soldWith($this, [])->ambassador_tier)->toBeNull();
});

test('the salesperson corrects the package until the handoff, a manager afterwards with why', function () {
    $project = soldWith($this, ['ambassadorTier' => 'bronze']);

    app(RecordAmbassadorPackage::class)->correct($project, ['ambassadorTier' => 'silver'], null, $this->sam);
    expect($project->fresh()->ambassador_tier)->toBe('silver');

    completeHandoff($project, $this->sam);

    expect(fn () => app(RecordAmbassadorPackage::class)->correct($project, ['ambassadorTier' => 'gold'], null, $this->sam))->toThrow(AuthorizationException::class);
    expect(fn () => app(RecordAmbassadorPackage::class)->correct($project, ['ambassadorTier' => 'gold'], '', $this->manager))->toThrow(ValidationException::class, 'Say why the package changes after the handoff.');

    app(RecordAmbassadorPackage::class)->correct($project, ['ambassadorTier' => 'gold'], 'The customer added the video.', $this->manager);
    expect($project->fresh()->ambassador_tier)->toBe('gold')
        ->and(AuditEvent::query()->where('action', AuditAction::ProjectAmbassadorPackageRecorded)->latest('id')->first()->reason)->toBe('The customer added the video.');
});

test('the package is chosen on the Proposals card when marking it sold, and shown on the job', function () {
    proposalsCard($this->sam, $this->lead)
        ->call('openResult', $this->proposal->id, 'sold')
        ->assertSeeHtml('data-test="ambassador-tier"')
        ->set('result.ambassadorTier', 'silver')
        ->call('saveResult')
        ->assertHasNoErrors();

    $project = Project::query()->sole();
    expect($project->ambassador_tier)->toBe('silver');

    Livewire::actingAs($this->sam)->test('pages::projects.ambassador', ['project' => $project])
        ->assertSee('Silver, 15%')
        ->assertSee('$9,941.18')
        ->assertSee('Named or phone reference')
        ->assertSeeHtml('data-test="record-ambassador-package"');
});

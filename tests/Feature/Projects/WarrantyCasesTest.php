<?php

use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\ManageWarrantyCases;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Actions\Staff\DeactivateStaffMember;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Subcontractor;
use App\Models\User;
use App\Models\WarrantyCase;
use App\Models\WorkPackageAward;
use App\Notifications\ProjectNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The Warranty Case, step 22a (docs/slice-22-warranty-plan.md; decision 177;
 * product-architecture.md, 16).
 *
 * It is Wednesday 14 October 2026 at Lakeview. "Oak St" was painted by
 * Sandia Brush Co.; Pat is its Production Manager, Pam another one there,
 * Morgan manages Lakeview, Sam sold it.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->pam = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pam Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-14', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-16', '2026-11-27', null, null, $this->pat);
    $package = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);

    $this->sandia = Subcontractor::factory()->create(['business_name' => 'Sandia Brush Co.']);
    (new WorkPackageAward)->forceFill([
        'work_package_id' => $package->id, 'subcontractor_id' => $this->sandia->id, 'payout_cents' => 425000,
        'first_day' => '2026-11-16', 'last_day' => '2026-11-20', 'accepted_by' => 'Rosa', 'acceptance_method' => 'phone', 'accepted_on' => '2026-10-14',
    ])->save();

    $this->project = $this->project->fresh();
});

function openWarranty($test, ?User $by = null): WarrantyCase
{
    return app(ManageWarrantyCases::class)->open($test->project, 'Peeling on the south fascia.', 'The customer, by phone', $by ?? $test->sam);
}

test('anyone who can see the job opens a case; it goes to its Production Manager, who is told', function () {
    Notification::fake();

    $case = openWarranty($this);

    expect($case->state)->toBe(WarrantyCase::OPEN)
        ->and($case->owner_id)->toBe($this->pat->id)
        ->and($case->original_subcontractor_ids)->toBe([$this->sandia->id])
        ->and(AuditEvent::query()->where('action', AuditAction::WarrantyCaseOpened)->sole()->subject_id)->toBe($this->project->id);
    Notification::assertSentTo($this->pat, ProjectNotice::class, fn (ProjectNotice $notice): bool => $notice->kind === ProjectNotice::WARRANTY_ASSIGNED);

    expect(fn () => app(ManageWarrantyCases::class)->open($this->project, ' ', 'The customer', $this->sam))->toThrow(ValidationException::class, 'Say what the customer reports.');
    expect(fn () => app(ManageWarrantyCases::class)->open($this->project, 'Drips.', '', $this->sam))->toThrow(ValidationException::class, 'Say who reported it, and how.');
});

test('with its Production Manager gone, the managers are told and one gives it to someone', function () {
    Notification::fake();
    app(DeactivateStaffMember::class)->handle($this->pat, $this->owner);

    $case = openWarranty($this);

    expect($case->owner_id)->toBeNull();
    Notification::assertSentTo($this->manager, ProjectNotice::class, fn (ProjectNotice $notice): bool => $notice->kind === ProjectNotice::WARRANTY_UNOWNED);

    expect(fn () => app(ManageWarrantyCases::class)->assign($case, $this->pam->id, $this->pam))->toThrow(AuthorizationException::class);
    expect(fn () => app(ManageWarrantyCases::class)->assign($case, $this->sam->id, $this->manager))->toThrow(ValidationException::class, 'Choose a Production Manager or a manager at Lakeview.');

    app(ManageWarrantyCases::class)->assign($case, $this->pam->id, $this->manager);
    expect($case->fresh()->owner_name)->toBe('Pam Production');
    Notification::assertSentTo($this->pam, ProjectNotice::class, fn (ProjectNotice $notice): bool => $notice->kind === ProjectNotice::WARRANTY_ASSIGNED);
});

test('its owner or a manager closes it without work, with why; nobody else does', function () {
    $case = openWarranty($this);

    expect(fn () => app(ManageWarrantyCases::class)->close($case, 'Not ours.', $this->sam))->toThrow(AuthorizationException::class);
    expect(fn () => app(ManageWarrantyCases::class)->close($case, ' ', $this->pat))->toThrow(ValidationException::class, 'Say why it is closed without work.');

    app(ManageWarrantyCases::class)->close($case, 'Storm damage, not workmanship.', $this->pat);

    expect($case->fresh()->state)->toBe(WarrantyCase::CLOSED)->and($case->fresh()->outcome)->toBe('Storm damage, not workmanship.');
    expect(fn () => app(ManageWarrantyCases::class)->close($case, 'Again.', $this->pat))->toThrow(ValidationException::class, 'This case is closed without work.');
    expect(fn () => $case->fresh()->delete())->toThrow(LogicException::class);
});

test('the job opens a case and lists it; the case has its own page; Production > Warranty lists it', function () {
    Livewire::actingAs($this->sam)->test('pages::projects.warranty', ['project' => $this->project])
        ->call('openCase')
        ->set('warrantyDescription', 'Peeling on the south fascia.')
        ->set('warrantyReportedBy', 'The customer, by phone')
        ->call('saveCase')
        ->assertHasNoErrors()
        ->assertRedirect(route('projects.warranty-case', [$this->project, WarrantyCase::query()->sole()]));

    $case = WarrantyCase::query()->sole();

    $this->actingAs($this->sam)->get(route('projects.warranty-case', [$this->project, $case]))->assertOk()
        ->assertSee('Peeling on the south fascia.')
        ->assertSee('Sandia Brush Co.')
        ->assertDontSeeHtml('data-test="close-warranty-case"');
    $this->actingAs($this->pat)->get(route('projects.warranty-case', [$this->project, $case]))->assertSeeHtml('data-test="close-warranty-case"');

    $this->actingAs($this->pat)->get(route('production.warranty'))->assertOk()
        ->assertSeeInOrder(['Open and scheduled', 'Whole exterior — 1 Oak St', 'Peeling on the south fascia.', 'Yours']);

    $elsewhere = soldProject(Location::factory()->create(), $this->sam);
    $this->actingAs($this->pat)->get(route('projects.warranty-case', [$elsewhere, $case]))->assertNotFound();
});

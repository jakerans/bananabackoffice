<?php

use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use App\Models\WorkPackagePayout;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Payout, step 13a (docs/slice-13-giving-work-plan.md; product-architecture.md,
 * 6.6 and 7.6; decision 151, questions 1 and 2): what Banana will pay a
 * subcontractor for a Work Package. Finance-sensitive.
 *
 * At Lakeview, "Oak St" is ready to schedule: Pat Production has it, called
 * the customer and set a rough timeframe. Its whole exterior is one package.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
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
    $this->project = $this->project->fresh();
});

function payoutSet($test, string $amount, ?string $reason = null, ?User $by = null)
{
    return app(ChangeWorkPackages::class)->setPayout($test->project, $test->package->id, $amount, $reason, $by ?? $test->pat);
}

test('a payout is set in dollars, kept with who set it, and is in the audit record only', function () {
    payoutSet($this, '$4,250');

    expect($this->package->fresh()->payout_cents)->toBe(425000);

    $first = WorkPackagePayout::query()->sole();

    expect($first->only(['amount_cents', 'reason', 'set_by', 'set_by_name']))->toBe(['amount_cents' => 425000, 'reason' => null, 'set_by' => $this->pat->id, 'set_by_name' => 'Pat Production']);
    expect($first->amount())->toBe('$4,250');

    $event = AuditEvent::query()->where('action', AuditAction::ProjectPayoutSet)->sole();

    expect($event->values_before)->toBeNull();
    expect($event->values_after)->toEqual(['package' => 'Whole exterior', 'payout_cents' => 425000]);
    expect(CustomerActivity::actions())->not->toContain(AuditAction::ProjectPayoutSet);
});

test('a changed payout keeps every amount, and the same amount again records nothing', function () {
    payoutSet($this, '4250');
    payoutSet($this, '4,600.50', '  Extra trim on the garage.  ');

    expect($this->package->fresh()->payout_cents)->toBe(460050);
    expect($this->package->payouts()->pluck('amount_cents')->all())->toBe([460050, 425000]);
    expect($this->package->payouts()->first()->reason)->toBe('Extra trim on the garage.');

    $changed = AuditEvent::query()->where('action', AuditAction::ProjectPayoutSet)->latest('id')->first();

    expect($changed->values_before)->toEqual(['package' => 'Whole exterior', 'payout_cents' => 425000]);
    expect($changed->reason)->toBe('Extra trim on the garage.');

    payoutSet($this, '$4,600.50');

    expect(WorkPackagePayout::query()->count())->toBe(2);

    // An amount a package had is kept as it was.
    expect(fn () => WorkPackagePayout::query()->first()->forceFill(['amount_cents' => 1])->save())->toThrow(LogicException::class);
    expect(fn () => WorkPackagePayout::query()->first()->delete())->toThrow(LogicException::class);
});

test('an amount that is not one is refused, and nothing is saved', function (string $amount) {
    expect(fn () => payoutSet($this, $amount))->toThrow(ValidationException::class, 'Give the payout in dollars, like 4,250 or 4,250.50.');

    expect($this->package->fresh()->payout_cents)->toBeNull();
    expect(WorkPackagePayout::query()->count())->toBe(0);
})->with(['', 'four thousand', '0', '-500', '4250.505', '20000000']);

test('a reason that is too long is refused', function () {
    expect(fn () => payoutSet($this, '4250', str_repeat('a', WorkPackagePayout::REASON_LENGTH + 1)))
        ->toThrow(ValidationException::class, 'Keep this to 1000 characters or fewer.');
});

test('who may see and set payouts', function (Closure $person, bool $sees, bool $sets) {
    $person = $person($this);

    expect($person->can('viewPayouts', $this->project))->toBe($sees);
    expect($person->can('managePayouts', $this->project))->toBe($sets);

    if ($sets) {
        expect(payoutSet($this, '4250', by: $person)->payout_cents)->toBe(425000);

        return;
    }

    expect(fn () => payoutSet($this, '4250', by: $person))->toThrow(AuthorizationException::class);
    expect(WorkPackagePayout::query()->count())->toBe(0);
})->with([
    'its Production Manager' => [fn ($test) => $test->pat, true, true],
    'a Location Manager there' => [fn ($test) => $test->manager, true, true],
    'the Owner' => [fn ($test) => $test->owner, true, true],
    'Leadership there' => [fn ($test) => User::factory()->withRoleAt(Role::Leadership, $test->lakeview)->create(), true, true],
    'Finance' => [fn ($test) => $test->finance, true, false],
    'another Production Manager there' => [fn ($test) => $test->pam, true, false],
    'the salesperson who sold it' => [fn ($test) => $test->sam, false, false],
    'a Production Manager elsewhere' => [fn ($test) => User::factory()->withRoleAt(Role::ProductionManager, Location::factory()->create())->create(), false, false],
]);

test('no payout is set on a job that is not ready to schedule', function () {
    app(RecordWelcomeCall::class)->undo($this->project, 'Ticked on the wrong job.', $this->pat);

    expect(fn () => payoutSet($this, '4250'))->toThrow(ValidationException::class, 'This job is not ready to schedule yet.');
});

test('the production manager sets the payout on the page; finance reads it; the salesperson never sees it', function () {
    Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $this->project])
        ->assertSeeHtml('data-test="work-package-payout"')
        ->assertSee('Not set yet')
        ->call('openPayout', $this->package->id)
        ->set('payoutAmount', 'lots')
        ->call('savePayout')
        ->assertHasErrors(['payoutAmount'])
        ->set('payoutAmount', '4,250')
        ->call('savePayout')
        ->assertHasNoErrors()
        ->assertSee('$4,250')
        ->call('openPayout', $this->package->id)
        ->assertSet('payoutAmount', '4250')
        ->set('payoutAmount', '4600')
        ->set('payoutReason', 'Extra trim.')
        ->call('savePayout')
        ->assertSee('$4,600')
        ->assertSeeHtml('data-test="work-payout-history"')
        ->assertSee('Was $4,250')
        ->assertSee('Extra trim.');

    Livewire::actingAs($this->finance)->test('pages::projects.work', ['project' => $this->project->fresh()])
        ->assertSee('$4,600')
        ->assertDontSeeHtml('data-test="set-work-payout"')
        ->call('openPayout', $this->package->id)
        ->assertForbidden();

    $sales = Livewire::actingAs($this->sam)->test('pages::projects.work', ['project' => $this->project->fresh()])
        ->assertSee('Whole exterior')
        ->assertDontSeeHtml('data-test="work-package-payout"')
        ->assertDontSee('$4,600')
        ->assertDontSee('$4,250')
        ->assertDontSeeHtml('name="work-payout"');

    $sales->call('openPayout', $this->package->id)->assertForbidden();

    // Nor in the Project's Activity, for anyone.
    $page = Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $this->project->fresh()])->html();

    expect((string) str($page)->after('data-test="project-activity"')->before('</ol>'))->not->toContain('4,600')->not->toContain('ayout');
});

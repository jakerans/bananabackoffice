<?php

use App\Actions\Compensation\ManagePay;
use App\Actions\Compensation\RunPayroll;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\CompensationEntry;
use App\Models\Location;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Compensation\PayPeriods;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Finance finalizes for payroll, step 20c (docs/slice-20-compensation-plan.md;
 * decision 172, question 3; decision 133).
 *
 * Sam has $250 and Pat $75 due in October 2026. Finley is Finance for every
 * Location; Fran is Finance at Lakeview only.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->finley = User::factory()->withRoleEverywhere(Role::Finance)->create(['name' => 'Finley Finance']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    app(ManagePay::class)->adjust($this->sam, 'add', '250', null, 'Spiff for the home show.', $this->finley);
    app(ManagePay::class)->adjust($this->pat, 'add', '75', null, 'Weekend callback.', $this->finley);
});

test('a month that is over is finalized and kept; a later entry is due in the next open period', function () {
    expect(fn () => app(RunPayroll::class)->finalize('2026-10', $this->finley))->toThrow(ValidationException::class, 'October 2026 is not over yet.');

    $this->travelTo('2026-11-02 15:00:00');
    $period = app(RunPayroll::class)->finalize('2026-10', $this->finley);

    expect($period->only(['period', 'total_cents', 'entries_count']))->toBe(['period' => '2026-10', 'total_cents' => 32500, 'entries_count' => 2])
        ->and(AuditEvent::query()->where('action', AuditAction::PayrollFinalized)->count())->toBe(1);
    expect(fn () => app(RunPayroll::class)->finalize('2026-10', $this->finley))->toThrow(ValidationException::class, 'October 2026 is already finalized.');

    // Anything due in October or before from now on, like a bonus settled late, is due in November.
    expect(PayPeriods::openFrom('2026-10'))->toBe('2026-11')
        ->and(PayPeriods::openFrom('2026-09'))->toBe('2026-11')
        ->and(PayPeriods::openFrom('2026-12'))->toBe('2026-12');
});

test('months are finalized in order', function () {
    $this->travelTo('2026-12-02 15:00:00');
    app(ManagePay::class)->adjust($this->sam, 'add', '10', null, 'December.', $this->finley);

    $this->travelTo('2027-01-04 15:00:00');
    expect(fn () => app(RunPayroll::class)->finalize('2026-12', $this->finley))->toThrow(ValidationException::class, 'Finalize October 2026 first.');

    app(RunPayroll::class)->finalize('2026-10', $this->finley);
    app(RunPayroll::class)->finalize('2026-12', $this->finley);

    expect(PayrollPeriod::query()->pluck('period')->all())->toBe(['2026-10', '2026-12']);
});

test('a finalized period is marked paid with payroll\'s reference, once', function () {
    $this->travelTo('2026-11-02 15:00:00');

    expect(fn () => app(RunPayroll::class)->markPaid('2026-10', 'GUSTO-1', $this->finley))->toThrow(ValidationException::class, 'Finalize October 2026 first.');

    app(RunPayroll::class)->finalize('2026-10', $this->finley);

    expect(fn () => app(RunPayroll::class)->markPaid('2026-10', ' ', $this->finley))->toThrow(ValidationException::class, 'Give payroll\'s reference for this run.');

    $paid = app(RunPayroll::class)->markPaid('2026-10', 'GUSTO-2026-10', $this->finley);

    expect($paid->payroll_reference)->toBe('GUSTO-2026-10')
        ->and($paid->state())->toBe(PayrollPeriod::STATE_PAID);
    expect(fn () => app(RunPayroll::class)->markPaid('2026-10', 'GUSTO-again', $this->finley))->toThrow(ValidationException::class, 'October 2026 is already marked paid.');
    expect(fn () => $paid->fresh()->forceFill(['total_cents' => 1])->save())->toThrow(LogicException::class);
    expect(fn () => $paid->fresh()->delete())->toThrow(LogicException::class);
});

test('Finance keeps each person\'s payroll ID, one person to each, and it goes to payroll', function () {
    app(ManagePay::class)->setPayrollId($this->sam, ' GUSTO-0042 ', $this->finley);

    expect($this->sam->fresh()->payroll_id)->toBe('GUSTO-0042')
        ->and(AuditEvent::query()->where('action', AuditAction::PayrollIdChanged)->count())->toBe(1);
    expect(fn () => app(ManagePay::class)->setPayrollId($this->pat, 'GUSTO-0042', $this->finley))->toThrow(ValidationException::class, 'Another person has this payroll ID.');
    expect(fn () => app(ManagePay::class)->setPayrollId($this->pat, 'GUSTO-7', $this->fran))->toThrow(AuthorizationException::class);
    expect(fn () => app(ManagePay::class)->setPayrollId($this->finley, 'GUSTO-1', $this->finley))->toThrow(AuthorizationException::class);

    Livewire::actingAs($this->finley)->test('pages::finance.payroll')
        ->assertSeeInOrder(['Pat Production', 'No payroll ID', 'Sam Sales', 'Payroll ID GUSTO-0042']);

    $this->travelTo('2026-11-02 15:00:00');
    app(RunPayroll::class)->finalize('2026-10', $this->finley);

    $csv = Livewire::actingAs($this->finley)->test('pages::finance.payroll', ['period' => '2026-10'])->call('download');
    $csv->assertFileDownloaded('payroll-2026-10.csv', '"Payroll ID",Person,Kind,Job,Amount,"How it was worked out",Why'."\n"
        .',"Pat Production","Manual adjustment",,75.00,"Added by Finley Finance.","Weekend callback."'."\n"
        .'GUSTO-0042,"Sam Sales","Manual adjustment",,250.00,"Added by Finley Finance.","Spiff for the home show."'."\n");
});

test('Finance sets a payroll ID from the person\'s page, and clears it', function () {
    Livewire::actingAs($this->finley)->test('pages::pay.ledger', ['user' => $this->sam])
        ->call('openPayrollId')
        ->set('payrollId', 'GUSTO-0042')
        ->call('savePayrollId')
        ->assertHasNoErrors()
        ->assertSee('GUSTO-0042')
        ->call('openPayrollId')
        ->set('payrollId', '')
        ->call('savePayrollId')
        ->assertSee('Not set');

    expect($this->sam->fresh()->payroll_id)->toBeNull();
});

test('only Finance for every Location, and the Owner, run payroll', function () {
    $this->travelTo('2026-11-02 15:00:00');

    foreach ([$this->fran, $this->sam] as $person) {
        expect(fn () => app(RunPayroll::class)->finalize('2026-10', $person))->toThrow(AuthorizationException::class);
    }
});

test('Finance finalizes, downloads and marks paid from the Payroll page, and each person sees where their pay stands', function () {
    $this->travelTo('2026-11-02 15:00:00');

    Livewire::actingAs($this->finley)->test('pages::finance.payroll')
        ->assertSet('period', '2026-10')
        ->assertSeeInOrder(['Open', 'Pat Production', '$75', 'Sam Sales', '$250', 'Total', '$325'])
        ->call('openFinalize')
        ->call('finalize')
        ->assertHasNoErrors()
        ->assertSee('Scheduled')
        ->assertSee('Finalized by Finley Finance on Nov 2, 2026: 2 entries, $325.');

    Livewire::actingAs($this->finley)->test('pages::finance.payroll', ['period' => '2026-10'])
        ->call('download')
        ->assertFileDownloaded('payroll-2026-10.csv');

    $this->actingAs($this->sam)->get(route('pay'))->assertSeeInOrder(['October 2026', 'Scheduled for payroll', '$250']);

    Livewire::actingAs($this->finley)->test('pages::finance.payroll', ['period' => '2026-10'])
        ->call('openPaid')
        ->set('payrollReference', 'GUSTO-2026-10')
        ->call('markPaid')
        ->assertHasNoErrors()
        ->assertSee('Marked paid by Finley Finance on Nov 2, 2026, payroll reference GUSTO-2026-10');

    $this->actingAs($this->sam)->get(route('pay'))->assertSeeInOrder(['October 2026', 'Paid', '$250']);
    expect(CompensationEntry::query()->count())->toBe(2);
});

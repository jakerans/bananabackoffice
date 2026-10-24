<?php

use App\Actions\Locations\ChangeDepositPolicy;
use App\Actions\Projects\RecordPayments;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use App\Notifications\ProjectNotice;
use App\Support\Projects\Payments;
use App\Support\Projects\RoughTimeframe;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The deposit, step 17b (docs/slice-17-customer-payments-plan.md; decisions
 * 162 and 163; product-architecture.md, 10.4 and 10.6).
 *
 * It is Friday 23 October 2026 at Lakeview. "Oak St" sold for $8,450. Pat
 * is its Production Manager, Morgan manages Lakeview, Fran and Flo are
 * Finance there, and Sam sold it.
 */

beforeEach(function () {
    $this->travelTo('2026-10-23 15:00:00');

    Storage::fake('files');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->flo = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Flo Finance']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
});

function depositOf($test): Payments
{
    return Payments::for($test->project->fresh());
}

function depositInvoiced($test, string $amount = '4,225'): int
{
    return app(RecordPayments::class)->recordInvoice($test->project, 'deposit', '1042', $amount, '2026-10-23', $test->fran)->id;
}

/*
|--------------------------------------------------------------------------
| The policy
|--------------------------------------------------------------------------
*/

test('the deposit asked for is the Location\'s percent of the sold price, 50 by default', function () {
    expect(depositOf($this)->requiredDepositCents())->toBe(422500);

    app(ChangeDepositPolicy::class)->handle($this->lakeview, '30', $this->owner);

    expect(depositOf($this)->requiredDepositCents())->toBe(253500);
    expect(AuditEvent::query()->where('action', AuditAction::LocationDepositPolicyChanged)->sole()->values_after)->toEqual(['deposit_percent' => 30]);
});

test('a policy that is not a whole percent up to 100 is refused', function (string $typed) {
    expect(fn () => app(ChangeDepositPolicy::class)->handle($this->lakeview, $typed, $this->owner))
        ->toThrow(ValidationException::class, 'Give a whole percent from 0 to 100.');
})->with(['101', 'half', '12.5', '']);

test('a manager of one Location does not set the policy', function () {
    expect(fn () => app(ChangeDepositPolicy::class)->handle($this->lakeview, '40', $this->manager))->toThrow(AuthorizationException::class);
    expect($this->lakeview->fresh()->deposit_percent)->toBe(50);
});

test('Settings > Locations shows and sets the deposit policy', function () {
    Livewire::actingAs($this->owner)->test('pages::settings.locations')
        ->assertSeeHtml('data-test="location-deposit-'.$this->lakeview->id.'"')
        ->call('editDeposit', $this->lakeview->id)
        ->assertSet('depositPercent', '50')
        ->set('depositPercent', '40')
        ->call('saveDeposit')
        ->assertHasNoErrors();

    expect($this->lakeview->fresh()->deposit_percent)->toBe(40);
});

/*
|--------------------------------------------------------------------------
| A smaller deposit, or none
|--------------------------------------------------------------------------
*/

test('a manager lowers a job\'s deposit with why, and it is suggested for the deposit invoice', function () {
    app(RecordPayments::class)->changeDeposit($this->project, '2,000', 'Repeat customer; the owner agreed.', $this->manager);

    expect(depositOf($this)->requiredDepositCents())->toBe(200000)
        ->and(depositOf($this)->depositChange->set_by_name)->toBe('Morgan Manager');
    expect(AuditEvent::query()->where('action', AuditAction::ProjectDepositChanged)->sole())
        ->values_after->toEqual(['required_cents' => 200000])
        ->reason->toBe('Repeat customer; the owner agreed.');

    Livewire::actingAs($this->fran)->test('pages::projects.payments', ['project' => $this->project])
        ->call('openInvoice', 'deposit')
        ->assertSet('invoiceAmount', '2000.00');
});

test('a waived deposit reads No deposit', function () {
    app(RecordPayments::class)->changeDeposit($this->project, '0', 'Commercial account, paid on completion.', $this->manager);

    expect(depositOf($this)->state())->toBe(Payments::NO_DEPOSIT);

    Livewire::actingAs($this->manager)->test('pages::projects.show', ['project' => $this->project])
        ->assertSee('Payment: no deposit')
        ->assertSee('Deposit waived');
});

test('what cannot be set as a deposit is refused', function (string $amount, string $reason, string $message) {
    expect(fn () => app(RecordPayments::class)->changeDeposit($this->project, $amount, $reason, $this->manager))
        ->toThrow(ValidationException::class, $message);
})->with([
    'more than the policy' => ['5,000', 'More, please.', 'The deposit can only be lowered: the policy asks for $4,225.'],
    'what it is already' => ['4,225', 'Same.', 'That is the deposit asked for already.'],
    'no reason' => ['2,000', ' ', 'Say why the deposit is changed.'],
    'not an amount' => ['some', 'Why not.', 'Give the deposit in dollars before tax'],
]);

test('only a manager changes a job\'s deposit', function (string $who) {
    expect(fn () => app(RecordPayments::class)->changeDeposit($this->project, '2,000', 'Asked nicely.', $this->{$who}))->toThrow(AuthorizationException::class);
})->with(['pat', 'fran', 'sam']);

test('the full deposit counts only when the policy\'s whole deposit was collected', function () {
    app(RecordPayments::class)->changeDeposit($this->project, '2,000', 'Repeat customer.', $this->manager);
    $invoice = depositInvoiced($this, '2,000');
    app(RecordPayments::class)->recordPayment($this->project, $invoice, '2,000', 'card', '2026-10-23', 'ch_1', null, $this->fran);

    expect(depositOf($this)->state())->toBe(Payments::DEPOSIT_PAID)
        ->and(depositOf($this)->fullDepositCollected())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Deposit paid, and the call within 24 hours
|--------------------------------------------------------------------------
*/

test('when the deposit is paid in full, the Production Manager is told and has 24 hours to call', function () {
    Notification::fake();
    app(TakeProject::class)->handle($this->project, $this->pat);
    $invoice = depositInvoiced($this);

    app(RecordPayments::class)->recordPayment($this->project, $invoice, '2,000', 'card', '2026-10-23', 'ch_1', null, $this->fran);
    expect($this->project->fresh()->deposit_paid_at)->toBeNull();

    app(RecordPayments::class)->recordPayment($this->project, $invoice, '2,225', 'card', '2026-10-23', 'ch_2', null, $this->fran);

    $project = $this->project->fresh();
    expect($project->deposit_paid_at->toDateTimeString())->toBe('2026-10-23 15:00:00')
        ->and(depositOf($this)->fullDepositCollected())->toBeTrue()
        ->and(RoughTimeframe::welcomeCallDueAt($project)?->toDateTimeString())->toBe('2026-10-24 15:00:00');
    Notification::assertSentTo($this->pat, ProjectNotice::class, fn (ProjectNotice $notice): bool => $notice->kind === ProjectNotice::DEPOSIT_PAID);

    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $project])
        ->assertSee('The deposit is paid: call within 24 hours, by');

    $this->travelTo('2026-10-24 15:00:00');
    expect(RoughTimeframe::welcomeCallIsBehind($project))->toBeTrue();
});

test('a job given to its Production Manager after the deposit is paid has 24 hours from then', function () {
    $invoice = depositInvoiced($this);
    app(RecordPayments::class)->recordPayment($this->project, $invoice, '4,225', 'card', '2026-10-23', 'ch_1', null, $this->fran);

    $this->travelTo('2026-10-26 09:00:00');
    app(TakeProject::class)->handle($this->project, $this->pat);

    expect(RoughTimeframe::welcomeCallDueAt($this->project->fresh())?->toDateTimeString())->toBe('2026-10-27 09:00:00');
});

test('nobody is told when the customer was called already, and a reversal takes the deposit back to due', function () {
    Notification::fake();
    app(TakeProject::class)->handle($this->project, $this->pat);
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-23', null, $this->pat);
    $invoice = depositInvoiced($this);
    $payment = app(RecordPayments::class)->recordPayment($this->project, $invoice, '4,225', 'card', '2026-10-23', 'ch_1', null, $this->fran);

    Notification::assertNotSentTo($this->pat, ProjectNotice::class, fn (ProjectNotice $notice): bool => $notice->kind === ProjectNotice::DEPOSIT_PAID);

    app(RecordPayments::class)->reversePayment($this->project, $payment->id, 'The card was declined.', $this->flo);

    expect($this->project->fresh()->deposit_paid_at)->toBeNull()
        ->and(depositOf($this)->state())->toBe(Payments::DEPOSIT_DUE);
});

/*
|--------------------------------------------------------------------------
| Approving cash and check (decision 163)
|--------------------------------------------------------------------------
*/

test('someone other than who recorded a cash payment approves it; the Owner is the exception', function () {
    $invoice = depositInvoiced($this);
    $cash = app(RecordPayments::class)->recordPayment($this->project, $invoice, '2,000', 'cash', '2026-10-23', null, UploadedFile::fake()->image('Receipt.jpg'), $this->fran);

    expect(fn () => app(RecordPayments::class)->approvePayment($this->project, $cash->id, $this->fran))
        ->toThrow(ValidationException::class, 'You recorded this payment. Someone else in Finance approves it once they see it in the bank account.');
    expect($this->project->fresh()->deposit_paid_at)->toBeNull();

    app(RecordPayments::class)->approvePayment($this->project, $cash->id, $this->flo);
    expect($cash->fresh()->approved_by_name)->toBe('Flo Finance');

    $check = app(RecordPayments::class)->recordPayment($this->project, $invoice, '2,225', 'check', '2026-10-23', '2231', UploadedFile::fake()->image('Check.jpg'), $this->owner);
    app(RecordPayments::class)->approvePayment($this->project, $check->id, $this->owner);

    expect($check->fresh()->approved_by_name)->toBe('Olive Owner')
        ->and($this->project->fresh()->deposit_paid_at)->not->toBeNull();
});

test('approving asks whether the money is in the bank account', function () {
    $invoice = depositInvoiced($this);
    $cash = app(RecordPayments::class)->recordPayment($this->project, $invoice, '2,000', 'cash', '2026-10-23', null, UploadedFile::fake()->image('Receipt.jpg'), $this->fran);

    Livewire::actingAs($this->flo)->test('pages::projects.payments', ['project' => $this->project])
        ->call('openApprove', $cash->id)
        ->assertSee('Is it in the bank account?')
        ->assertSee('Approve it once you see it in the bank account');
});

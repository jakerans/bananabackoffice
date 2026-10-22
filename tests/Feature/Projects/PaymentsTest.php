<?php

use App\Actions\Projects\RecordPayments;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\ProjectInvoice;
use App\Models\ProjectPayment;
use App\Models\User;
use App\Support\Projects\Payments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Customer payments, step 17a (docs/slice-17-customer-payments-plan.md;
 * decision 162; decision 133).
 *
 * It is Thursday 22 October 2026 at Lakeview. "Oak St" sold for $8,450.
 * Pat is its Production Manager, Morgan manages Lakeview, Fran is Finance
 * there, Sam sold it, and Hal runs production at Harbor.
 */

beforeEach(function () {
    $this->travelTo('2026-10-22 15:00:00');

    Storage::fake('files');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->hal = User::factory()->withRoleAt(Role::ProductionManager, $this->harbor)->create(['name' => 'Hal Harbor']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    $this->project = $this->project->fresh();
});

function invoiceFor($test, string $kind = 'deposit', string $amount = '4,225', ?User $by = null): ProjectInvoice
{
    return app(RecordPayments::class)->recordInvoice($test->project, $kind, $kind === 'deposit' ? '1042' : '1077', $amount, '2026-10-20', $by ?? $test->pat);
}

function paidBy($test, ProjectInvoice $invoice, string $amount, string $method = 'card', ?UploadedFile $receipt = null, ?User $by = null): ProjectPayment
{
    return app(RecordPayments::class)->recordPayment($test->project, $invoice->id, $amount, $method, '2026-10-21', $method === 'card' ? 'ch_3Pq81x' : null, $receipt, $by ?? $test->pat);
}

function standingOf($test): Payments
{
    return Payments::for($test->project->fresh());
}

/*
|--------------------------------------------------------------------------
| The payment state
|--------------------------------------------------------------------------
*/

test('the payment state runs Deposit due, Deposit paid, Balance due and Paid in full', function () {
    expect(standingOf($this)->state())->toBe(Payments::DEPOSIT_DUE);

    $deposit = invoiceFor($this);
    paidBy($this, $deposit, '2,000');
    expect(standingOf($this)->state())->toBe(Payments::DEPOSIT_DUE)
        ->and(standingOf($this)->balanceCents())->toBe(222500);

    paidBy($this, $deposit, '2,225');
    expect(standingOf($this)->state())->toBe(Payments::DEPOSIT_PAID);

    $final = invoiceFor($this, 'final', '4,562.75');
    expect(standingOf($this)->state())->toBe(Payments::BALANCE_DUE)
        ->and(standingOf($this)->invoicedCents())->toBe(878775)
        ->and(standingOf($this)->balance())->toBe('$4,562.75');

    paidBy($this, $final, '4,562.75');
    expect(standingOf($this)->state())->toBe(Payments::PAID_IN_FULL)
        ->and(standingOf($this)->balanceCents())->toBe(0);
});

test('each invoice and payment is in the job\'s history, under its Location', function () {
    $deposit = invoiceFor($this);
    paidBy($this, $deposit, '4,225');

    $recorded = AuditEvent::query()->where('action', AuditAction::ProjectInvoiceRecorded)->sole();
    expect($recorded->subject_id)->toBe($this->project->id)
        ->and($recorded->location_id)->toBe($this->lakeview->id)
        ->and($recorded->values_after)->toEqual(['invoice' => 'Deposit invoice', 'number' => '1042', 'amount_cents' => 422500, 'sent_on' => '2026-10-20']);

    expect(AuditEvent::query()->where('action', AuditAction::ProjectPaymentRecorded)->sole()->values_after)
        ->toEqual(['invoice' => 'Deposit invoice', 'amount_cents' => 422500, 'method' => 'Card', 'received_on' => '2026-10-21', 'awaiting_approval' => false]);
});

/*
|--------------------------------------------------------------------------
| Invoices
|--------------------------------------------------------------------------
*/

test('an invoice that cannot be recorded is refused', function (?string $kind, ?string $number, ?string $amount, string $sentOn, string $field, string $message) {
    expect(fn () => app(RecordPayments::class)->recordInvoice($this->project, $kind, $number, $amount, $sentOn, $this->pat))
        ->toThrow(ValidationException::class, $message);

    expect(ProjectInvoice::query()->count())->toBe(0);
})->with([
    'no kind' => [null, '1042', '4225', '2026-10-20', 'invoiceKind', 'Choose the deposit or the final invoice.'],
    'no number' => ['deposit', '  ', '4225', '2026-10-20', 'invoiceNumber', 'Give the invoice number from QuickBooks.'],
    'not an amount' => ['deposit', '1042', 'about four grand', '2026-10-20', 'invoiceAmount', 'Give the total in dollars'],
    'sent tomorrow' => ['deposit', '1042', '4225', '2026-10-23', 'invoiceSentOn', 'The day it was sent cannot be in the future.'],
]);

test('a job has one deposit and one final invoice; a wrong one is voided with why, and recorded again', function () {
    $deposit = invoiceFor($this, amount: '4,000');

    expect(fn () => invoiceFor($this))->toThrow(ValidationException::class, 'This job has its deposit invoice already. Void it first');
    expect(fn () => app(RecordPayments::class)->voidInvoice($this->project, $deposit->id, ' ', $this->pat))->toThrow(ValidationException::class, 'Say why it is voided.');

    app(RecordPayments::class)->voidInvoice($this->project, $deposit->id, 'Recorded before tax was added.', $this->pat);
    invoiceFor($this, amount: '4,225');

    expect($deposit->fresh()->voided_reason)->toBe('Recorded before tax was added.')
        ->and(standingOf($this)->deposit()->amount_cents)->toBe(422500)
        ->and(ProjectInvoice::query()->count())->toBe(2);
});

test('an invoice with a payment standing against it is not voided until Finance reverses the payment', function () {
    $deposit = invoiceFor($this);
    $payment = paidBy($this, $deposit, '4,225');

    expect(fn () => app(RecordPayments::class)->voidInvoice($this->project, $deposit->id, 'Wrong total.', $this->pat))
        ->toThrow(ValidationException::class, 'A payment stands against this invoice. Finance reverses it first.');

    app(RecordPayments::class)->reversePayment($this->project, $payment->id, 'Recorded on the wrong job.', $this->fran);
    app(RecordPayments::class)->voidInvoice($this->project, $deposit->id, 'Wrong total.', $this->pat);

    expect($deposit->fresh()->isVoided())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
*/

test('a payment that cannot be recorded is refused', function (string $amount, ?string $method, ?string $reference, string $message) {
    $deposit = invoiceFor($this);

    expect(fn () => app(RecordPayments::class)->recordPayment($this->project, $deposit->id, $amount, $method, '2026-10-21', $reference, null, $this->pat))
        ->toThrow(ValidationException::class, $message);

    expect(ProjectPayment::query()->count())->toBe(0);
})->with([
    'no method' => ['4225', null, 'ch_1', 'Choose how they paid.'],
    'more than owed' => ['4300', 'card', 'ch_1', 'This is more than the $4,225 still owed on this invoice.'],
    'a card payment without its reference' => ['4225', 'card', '', 'Give the payment\'s reference from the processor or QuickBooks.'],
    'cash without a receipt' => ['4225', 'cash', null, 'Add a photo or scan of the receipt. Cash and check need it.'],
]);

test('a paid invoice takes no more payments', function () {
    $deposit = invoiceFor($this);
    paidBy($this, $deposit, '4,225');

    expect(fn () => paidBy($this, $deposit, '1'))->toThrow(ValidationException::class, 'This invoice is paid already.');
});

test('cash and check wait for Finance\'s approval, with their receipt, and count once approved', function () {
    $deposit = invoiceFor($this);
    $check = paidBy($this, $deposit, '4,225', 'check', UploadedFile::fake()->image('Check 2231.jpg'));

    $receipt = $check->receipts()->sole();
    expect($receipt->kind)->toBe('receipt')
        ->and($receipt->path)->toStartWith('project_payment/'.$check->id.'/');
    Storage::disk('files')->assertExists($receipt->path);

    expect($check->isAwaitingApproval())->toBeTrue()
        ->and(standingOf($this)->state())->toBe(Payments::DEPOSIT_DUE)
        ->and(standingOf($this)->awaitingApprovalCents())->toBe(422500);

    // Nothing more can be recorded against what waits for approval.
    expect(fn () => paidBy($this, $deposit, '1'))->toThrow(ValidationException::class, 'This invoice is paid already.');

    expect(fn () => app(RecordPayments::class)->approvePayment($this->project, $check->id, $this->pat))->toThrow(AuthorizationException::class);

    app(RecordPayments::class)->approvePayment($this->project, $check->id, $this->fran);

    expect($check->fresh()->approved_by_name)->toBe('Fran Finance')
        ->and(standingOf($this)->state())->toBe(Payments::DEPOSIT_PAID);
    expect(fn () => app(RecordPayments::class)->approvePayment($this->project, $check->id, $this->fran))->toThrow(ValidationException::class, 'This payment does not wait for approval.');
});

test('Finance reverses a payment with why; it stops counting and is kept', function () {
    $deposit = invoiceFor($this);
    $payment = paidBy($this, $deposit, '4,225');

    expect(fn () => app(RecordPayments::class)->reversePayment($this->project, $payment->id, 'Declined.', $this->pat))->toThrow(AuthorizationException::class);
    expect(fn () => app(RecordPayments::class)->reversePayment($this->project, $payment->id, '', $this->fran))->toThrow(ValidationException::class, 'Say why it is reversed.');

    app(RecordPayments::class)->reversePayment($this->project, $payment->id, 'The card was declined.', $this->fran);

    expect($payment->fresh()->reversed_reason)->toBe('The card was declined.')
        ->and(standingOf($this)->state())->toBe(Payments::DEPOSIT_DUE)
        ->and(ProjectPayment::query()->count())->toBe(1);
    expect(AuditEvent::query()->where('action', AuditAction::ProjectPaymentReversed)->sole()->reason)->toBe('The card was declined.');
});

test('invoices and payments are never changed in place or deleted', function () {
    $deposit = invoiceFor($this);
    $payment = paidBy($this, $deposit, '4,225');

    expect(fn () => $deposit->forceFill(['amount_cents' => 1])->save())->toThrow(LogicException::class);
    expect(fn () => $deposit->delete())->toThrow(LogicException::class);
    expect(fn () => $payment->forceFill(['amount_cents' => 1])->save())->toThrow(LogicException::class);
    expect(fn () => $payment->delete())->toThrow(LogicException::class);
});

/*
|--------------------------------------------------------------------------
| Who
|--------------------------------------------------------------------------
*/

test('the Production Manager, a manager or Finance records invoices and payments; nobody else does', function () {
    invoiceFor($this, by: $this->manager);
    $final = invoiceFor($this, 'final', '4,562.75', $this->fran);
    paidBy($this, $final, '100', by: $this->pat);

    foreach ([$this->sam, $this->hal] as $person) {
        expect(fn () => paidBy($this, $final, '100', by: $person))->toThrow(AuthorizationException::class);
    }
});

test('a deposit can be recorded at the sale, before there is a Production Manager', function () {
    $other = soldProject($this->lakeview, $this->sam, ['title' => 'Kitchen — 9 Elm St']);

    $invoice = app(RecordPayments::class)->recordInvoice($other, 'deposit', '1100', '3000', '2026-10-22', $this->fran);

    expect($invoice->project_id)->toBe($other->id);
});

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

test('the job page shows the payment state, invoices and payments; only those allowed get the buttons', function () {
    $deposit = invoiceFor($this);
    $check = paidBy($this, $deposit, '4,225', 'check', UploadedFile::fake()->image('Check 2231.jpg'));

    Livewire::actingAs($this->sam)->test('pages::projects.payments', ['project' => $this->project])
        ->assertSee('Deposit due')
        ->assertSee('Deposit invoice #1042')
        ->assertSee('Waits for Finance\'s approval; it does not count until then.')
        ->assertSeeHtml('href="'.route('attachments.open', $check->receipts()->sole()).'"')
        ->assertDontSeeHtml('data-test="record-payment"')
        ->assertDontSeeHtml('data-test="approve-payment"');

    Livewire::actingAs($this->pat)->test('pages::projects.payments', ['project' => $this->project])
        ->assertSeeHtml('data-test="record-final-invoice"')
        ->assertDontSeeHtml('data-test="approve-payment"');

    Livewire::actingAs($this->fran)->test('pages::projects.payments', ['project' => $this->project])
        ->call('openApprove', $check->id)
        ->call('approvePayment')
        ->assertHasNoErrors()
        ->assertDispatched('project-payments-changed')
        ->assertSee('Deposit paid');

    Livewire::actingAs($this->hal)->test('pages::projects.payments', ['project' => $this->project])->assertForbidden();
});

test('recording from the page: the invoice with a suggested amount, then a payment with its receipt', function () {
    $page = Livewire::actingAs($this->pat)->test('pages::projects.payments', ['project' => $this->project])
        ->assertSee('No invoice recorded yet.')
        ->call('openInvoice', 'deposit')
        ->assertSet('invoiceAmount', '4225.00')
        ->assertSet('invoiceSentOn', '2026-10-22')
        ->set('invoiceNumber', '1042')
        ->call('saveInvoice')
        ->assertHasNoErrors()
        ->assertSee('Deposit invoice #1042');

    $deposit = ProjectInvoice::query()->sole();

    $page->call('openPayment', $deposit->id)
        ->assertSet('paymentAmount', '4225.00')
        ->set('paymentMethod', 'cash')
        ->call('savePayment')
        ->assertHasErrors(['paymentReceipt'])
        ->set('paymentReceipt', UploadedFile::fake()->image('Receipt.jpg'))
        ->call('savePayment')
        ->assertHasNoErrors()
        ->assertSee('Waits for Finance\'s approval');

    expect(ProjectPayment::query()->sole()->method)->toBe('cash')
        ->and(Attachment::query()->sole()->kind)->toBe('receipt');
});

test('the job page carries the payment state beside its others, and its activity names each payment', function () {
    $deposit = invoiceFor($this);
    paidBy($this, $deposit, '4,225');

    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $this->project])
        ->assertSee('Payment: deposit paid')
        ->assertSeeLivewire('pages::projects.payments')
        ->assertSee('Deposit invoice recorded: #1042, $4,225')
        ->assertSee('Payment recorded: $4,225 by card toward the deposit invoice');
});

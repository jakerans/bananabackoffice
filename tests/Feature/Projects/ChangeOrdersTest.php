<?php

use App\Actions\Projects\RecordChangeOrders;
use App\Actions\Projects\RecordPayments;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\ProjectChangeOrder;
use App\Models\User;
use App\Support\Projects\Payments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Customer change orders, step 18a (docs/slice-18-change-orders-plan.md;
 * decision 166; product-architecture.md, 6.7).
 *
 * It is Monday 26 October 2026 at Lakeview. "Oak St" sold for $8,450. Pat
 * is its Production Manager, Morgan manages Lakeview, Fran is Finance, Sam
 * sold it, and Hal runs production at Harbor.
 */

beforeEach(function () {
    $this->travelTo('2026-10-26 15:00:00');

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

function drafted($test, string $price = '1,200', string $days = '2', ?string $reason = null, ?User $by = null): ProjectChangeOrder
{
    return app(RecordChangeOrders::class)->draft($test->project, 'Add the back bedroom: walls and ceiling.', $price, $days, $reason, $by ?? $test->pat);
}

function signedCopy(): UploadedFile
{
    return UploadedFile::fake()->create('Change order 1 signed.pdf', 120, 'application/pdf');
}

function signedOff($test, ProjectChangeOrder $order, ?UploadedFile $copy = null): ProjectChangeOrder
{
    return app(RecordChangeOrders::class)->sign($test->project, $order->id, 'Pat Customer', 'email', '2026-10-26', $copy ?? signedCopy(), $test->pat);
}

function contractOf($test): int
{
    return Payments::for($test->project->fresh())->contractCents();
}

/*
|--------------------------------------------------------------------------
| Drafting and signing
|--------------------------------------------------------------------------
*/

test('a change order counts once the customer signs it, with the signed copy', function () {
    $order = drafted($this);

    expect($order->number)->toBe(1)
        ->and($order->isDraft())->toBeTrue()
        ->and(contractOf($this))->toBe(845000);

    signedOff($this, $order);

    $order->refresh();
    expect($order->isSigned())->toBeTrue()
        ->and($order->signed_by_person)->toBe('Pat Customer')
        ->and($order->signed_method->value)->toBe('email')
        ->and(contractOf($this))->toBe(965000)
        ->and($this->project->fresh()->sold_cents)->toBe(845000);

    $copy = $order->signedCopies()->sole();
    expect($copy->path)->toStartWith('project_change_order/'.$order->id.'/');
    Storage::disk('files')->assertExists($copy->path);

    expect(AuditEvent::query()->where('action', AuditAction::ProjectChangeOrderSigned)->sole()->values_after)
        ->toEqual(['number' => 1, 'price_change_cents' => 120000, 'signed_by' => 'Pat Customer', 'method' => 'email', 'signed_on' => '2026-10-26']);
});

test('change orders are numbered on their job', function () {
    drafted($this);
    $second = drafted($this, '0', '1');

    expect($second->number)->toBe(2)
        ->and($second->price_change_cents)->toBe(0)
        ->and($second->priceChange())->toBe('No price change');
});

test('a change order that cannot be drafted is refused', function (string $description, string $price, string $days, string $message) {
    expect(fn () => app(RecordChangeOrders::class)->draft($this->project, $description, $price, $days, null, $this->pat))
        ->toThrow(ValidationException::class, $message);

    expect(ProjectChangeOrder::query()->count())->toBe(0);
})->with([
    'nothing said' => ['  ', '1200', '0', 'Say what changes.'],
    'not a price' => ['Add a room.', 'about a grand', '0', 'Give the price change in dollars before tax'],
    'too many days' => ['Add a room.', '1200', '61', 'Give the working days it adds, from 0 to 60.'],
]);

test('only a manager lowers the price, and says why', function () {
    expect(fn () => drafted($this, '-300', '0', 'Skipping the shed.'))->toThrow(ValidationException::class, 'Only a manager can lower the price.');
    expect(fn () => drafted($this, '-300', '0', null, $this->manager))->toThrow(ValidationException::class, 'Say why the price comes down.');

    $order = drafted($this, '-300', '0', 'Skipping the shed.', $this->manager);
    signedOff($this, $order);

    expect($order->fresh()->priceChange())->toBe('-$300')
        ->and(contractOf($this))->toBe(815000);
});

test('a signature that cannot be recorded is refused, and the change order stays a draft', function (string $person, string $method, string $day, bool $copy, string $message) {
    $order = drafted($this);

    expect(fn () => app(RecordChangeOrders::class)->sign($this->project, $order->id, $person, $method, $day, $copy ? signedCopy() : null, $this->pat))
        ->toThrow(ValidationException::class, $message);

    expect($order->fresh()->isDraft())->toBeTrue()
        ->and(Attachment::query()->count())->toBe(0)
        ->and(Storage::disk('files')->allFiles())->toBe([]);
})->with([
    'nobody' => ['', 'email', '2026-10-26', true, 'Say who signed it.'],
    'by phone' => ['Pat Customer', 'phone', '2026-10-26', true, 'Choose how they signed it.'],
    'tomorrow' => ['Pat Customer', 'email', '2026-10-27', true, 'The day it was signed cannot be in the future.'],
    'no signed copy' => ['Pat Customer', 'email', '2026-10-26', false, 'Attach the signed change order: a photo or PDF.'],
]);

test('a draft is withdrawn with why; a signed one is kept as it was', function () {
    $draft = drafted($this);
    expect(fn () => app(RecordChangeOrders::class)->withdraw($this->project, $draft->id, ' ', $this->pat))->toThrow(ValidationException::class, 'Say why it is withdrawn.');

    app(RecordChangeOrders::class)->withdraw($this->project, $draft->id, 'The customer changed their mind.', $this->pat);
    expect($draft->fresh()->isWithdrawn())->toBeTrue()
        ->and(contractOf($this))->toBe(845000);

    $signed = signedOff($this, drafted($this));
    expect(fn () => app(RecordChangeOrders::class)->withdraw($this->project, $signed->id, 'Oops.', $this->pat))->toThrow(ValidationException::class, 'This change order was changed since.');
    expect(fn () => $signed->fresh()->forceFill(['price_change_cents' => 1])->save())->toThrow(LogicException::class);
    expect(fn () => $signed->fresh()->delete())->toThrow(LogicException::class);
});

test('the Production Manager or a manager handles change orders; nobody else does', function (string $who) {
    expect(fn () => drafted($this, by: $this->{$who}))->toThrow(AuthorizationException::class);
})->with(['sam', 'hal', 'fran']);

/*
|--------------------------------------------------------------------------
| The final invoice, and the page
|--------------------------------------------------------------------------
*/

test('the final invoice is suggested from the contract total, less the deposit invoice', function () {
    app(RecordPayments::class)->recordInvoice($this->project, 'deposit', '1042', '4,225', '2026-10-20', $this->fran);
    signedOff($this, drafted($this));

    Livewire::actingAs($this->fran)->test('pages::projects.payments', ['project' => $this->project])
        ->call('openInvoice', 'final')
        ->assertSet('invoiceAmount', '5425.00');
});

test('the job page shows each change order and the contract total; only those allowed get the buttons', function () {
    $draft = drafted($this);
    signedOff($this, drafted($this, '500', '0'));

    Livewire::actingAs($this->sam)->test('pages::projects.change-orders', ['project' => $this->project])
        ->assertSeeInOrder(['Change order 1', 'Waiting for the customer', 'Change order 2', 'Signed'])
        ->assertSee('$8,950')
        ->assertDontSeeHtml('data-test="new-change-order"')
        ->assertDontSeeHtml('data-test="sign-change-order"');

    Livewire::actingAs($this->pat)->test('pages::projects.change-orders', ['project' => $this->project])
        ->assertSeeHtml('wire:click="openSign('.$draft->id.')"');

    Livewire::actingAs($this->hal)->test('pages::projects.change-orders', ['project' => $this->project])->assertForbidden();
});

test('drafting and signing from the page', function () {
    // The clock is ahead of today, so Livewire would clear today's uploads as more than a day old.
    config(['livewire.temporary_file_upload.cleanup' => false]);

    $page = Livewire::actingAs($this->pat)->test('pages::projects.change-orders', ['project' => $this->project])
        ->assertSee('No change orders.')
        ->call('openDraft')
        ->set('changeDescription', 'Add the garage door.')
        ->set('changePrice', '350')
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->assertSee('Add the garage door.');

    $order = ProjectChangeOrder::query()->sole();

    $page->call('openSign', $order->id)
        ->set('signedBy', 'Pat Customer')
        ->set('signedMethod', 'in_person')
        ->call('saveSigned')
        ->assertHasErrors(['signedCopy'])
        ->set('signedCopy', signedCopy())
        ->call('saveSigned')
        ->assertHasNoErrors()
        ->assertDispatched('project-payments-changed')
        ->assertSee('$8,800');

    expect($order->fresh()->isSigned())->toBeTrue();
});

test('the job\'s activity names each change order', function () {
    $order = drafted($this);
    signedOff($this, $order);

    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $this->project])
        ->assertSee('Change order 1 drafted: +$1,200')
        ->assertSee('Change order 1 signed by Pat Customer: +$1,200');
});

<?php

use App\Actions\Projects\RecordPayments;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Support\Projects\Payments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The final balance and who chases it, step 17c (docs/slice-17-customer-payments-plan.md;
 * decision 162, questions 5 and 6; product-architecture.md, 9.2 and 10.6).
 *
 * At Lakeview, "Oak St" sold for $8,450; its deposit of $4,225 is paid, and
 * the final invoice for $4,562.75 went out on Monday 2 November 2026. Pat is
 * its Production Manager, Fran and Flo are Finance at Lakeview, Hana is
 * Finance at Harbor, and Sam sold it.
 */

beforeEach(function () {
    $this->travelTo('2026-11-02 15:00:00');

    Storage::fake('files');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->flo = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Flo Finance']);
    $this->hana = User::factory()->withRoleAt(Role::Finance, $this->harbor)->create(['name' => 'Hana Harbor']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);

    $record = app(RecordPayments::class);
    $deposit = $record->recordInvoice($this->project, 'deposit', '1042', '4,225', '2026-10-20', $this->fran);
    $record->recordPayment($this->project, $deposit->id, '4,225', 'card', '2026-10-20', 'ch_1', null, $this->fran);
    $this->final = $record->recordInvoice($this->project, 'final', '1077', '4,562.75', '2026-11-02', $this->fran);
});

function owedOnOakSt($test): Payments
{
    return Payments::for(Project::query()->findOrFail($test->project->id));
}

/*
|--------------------------------------------------------------------------
| When it is chased
|--------------------------------------------------------------------------
*/

test('the balance is due seven days after the final invoice, late from then, and with Collections from day 30', function () {
    expect(owedOnOakSt($this)->balanceDueOn()->toDateString())->toBe('2026-11-09')
        ->and(owedOnOakSt($this)->isLate())->toBeFalse();

    $this->travelTo('2026-11-09 10:00:00');
    expect(owedOnOakSt($this)->isLate())->toBeTrue()
        ->and(owedOnOakSt($this)->inCollections())->toBeFalse()
        ->and(owedOnOakSt($this)->chasedBy())->toBe('Pat Production');

    $this->travelTo('2026-12-02 10:00:00');
    expect(owedOnOakSt($this)->inCollections())->toBeTrue()
        ->and(owedOnOakSt($this)->chasedBy())->toBe('Collections');
});

test('it is chased on days 1, 2, 3, 7 and 14, then weekly, until Collections has it', function () {
    $chased = [];

    foreach (range(0, 35) as $day) {
        $this->travelTo(now()->setDate(2026, 11, 2)->addDays($day)->setTime(10, 0));

        if (owedOnOakSt($this)->isToChaseToday()) {
            $chased[] = $day;
        }
    }

    expect($chased)->toBe([1, 2, 3, 7, 14, 21, 28]);
});

test('a contact today takes it off today\'s list, until its next chase day', function () {
    $this->travelTo('2026-11-03 10:00:00');
    expect(owedOnOakSt($this)->isToChaseToday())->toBeTrue();

    app(RecordPayments::class)->recordContact($this->project, '  Left a voicemail.  ', $this->pat);

    expect(owedOnOakSt($this)->isToChaseToday())->toBeFalse()
        ->and(owedOnOakSt($this)->contacts->first()->note)->toBe('Left a voicemail.');
    expect(AuditEvent::query()->where('action', AuditAction::ProjectCollectionContactRecorded)->sole()->reason)->toBe('Left a voicemail.');

    $this->travelTo('2026-11-04 10:00:00');
    expect(owedOnOakSt($this)->isToChaseToday())->toBeTrue();
});

test('nothing is chased once it is paid in full, and a contact is refused then', function () {
    $this->travelTo('2026-11-03 10:00:00');
    app(RecordPayments::class)->recordPayment($this->project, $this->final->id, '4,562.75', 'ach', '2026-11-03', 'ach_9', null, $this->fran);

    expect(owedOnOakSt($this)->isToChaseToday())->toBeFalse()
        ->and(owedOnOakSt($this)->state())->toBe(Payments::PAID_IN_FULL);
    expect(fn () => app(RecordPayments::class)->recordContact($this->project, null, $this->pat))
        ->toThrow(ValidationException::class, 'Nothing is owed on the final invoice.');
});

test('the Production Manager, a manager or Finance records a contact; nobody else does', function () {
    foreach ([$this->pat, $this->manager, $this->fran] as $person) {
        app(RecordPayments::class)->recordContact($this->project, null, $person);
    }

    expect(fn () => app(RecordPayments::class)->recordContact($this->project, null, $this->sam))->toThrow(AuthorizationException::class);
    expect(owedOnOakSt($this)->contacts)->toHaveCount(3);
});

/*
|--------------------------------------------------------------------------
| On the job, and on the Workspace
|--------------------------------------------------------------------------
*/

test('the job\'s Payments section says when it is due, then late, then with Collections, and records a contact', function () {
    $card = fn () => Livewire::actingAs($this->pat)->test('pages::projects.payments', ['project' => $this->project]);

    $card()->assertSee('The balance is due on Nov 9, 2026.');

    $this->travelTo('2026-11-09 10:00:00');
    $card()->assertSee('A late fee applies; add it to the invoice in QuickBooks.')
        ->assertSee('Contact the customer about it today.')
        ->call('openContact')
        ->set('contactNote', 'Promised to pay Friday.')
        ->call('saveContact')
        ->assertHasNoErrors()
        ->assertDontSee('Contact the customer about it today.')
        ->assertSee('Contacted Nov 9 by Pat Production')
        ->assertSee('Promised to pay Friday.');

    $this->travelTo('2026-12-02 10:00:00');
    $card()->assertSee('Still owed 30 days after the final invoice: Finance chases it now.');
});

test('the Workspace lists the balances to chase today, and none on other days', function () {
    $workspace = fn () => Livewire::actingAs($this->pat)->test('pages::production.workspace');

    $workspace()->assertDontSeeHtml('data-test="workspace-to-chase"');

    $this->travelTo('2026-11-03 10:00:00');
    $workspace()->assertSeeHtml('data-test="workspace-to-chase"')
        ->assertSee('$4,562.75 owed; the final invoice went out yesterday.');

    $this->travelTo('2026-12-02 10:00:00');
    $workspace()->assertDontSeeHtml('data-test="workspace-to-chase"');
});

/*
|--------------------------------------------------------------------------
| Finance > Receivables
|--------------------------------------------------------------------------
*/

test('Receivables groups each balance by days since the final invoice, with who chases it', function () {
    $other = soldProject($this->lakeview, $this->sam, ['title' => 'Kitchen — 9 Elm St']);
    $deposit = app(RecordPayments::class)->recordInvoice($other, 'deposit', '1100', '3,000', '2026-10-30', $this->fran);

    $page = fn () => Livewire::actingAs($this->fran)->test('pages::finance.receivables');

    $page()->assertSee('Final invoice #1077, sent today; due Nov 9, 2026')
        ->assertSeeInOrder(['Not yet due', 'Whole exterior — 1 Oak St', 'Late, days 7 to 13', 'Nothing here.', 'Late, days 14 to 29', 'Nothing here.', 'Collections, 30 days or more', 'Nothing here.'])
        ->assertSeeInOrder(['Deposits not yet paid', 'Kitchen — 9 Elm St', '$3,000'])
        ->assertSee('$7,562.75');

    $this->travelTo('2026-11-17 10:00:00');
    $page()->assertSeeInOrder(['Late, days 14 to 29', 'Whole exterior — 1 Oak St', 'late fee applies', 'chased by Pat Production']);

    $this->travelTo('2026-12-03 10:00:00');
    $page()->assertSeeInOrder(['Collections, 30 days or more', 'Whole exterior — 1 Oak St', 'chased by Collections']);

    expect($deposit->project_id)->toBe($other->id);
});

test('Receivables lists cash and check waiting for approval', function () {
    $other = soldProject($this->lakeview, $this->sam, ['title' => 'Kitchen — 9 Elm St']);
    $deposit = app(RecordPayments::class)->recordInvoice($other, 'deposit', '1100', '3,000', '2026-10-30', $this->fran);
    app(RecordPayments::class)->recordPayment($other, $deposit->id, '3,000', 'check', '2026-11-01', '2231', UploadedFile::fake()->image('Check.jpg'), $this->fran);

    Livewire::actingAs($this->flo)->test('pages::finance.receivables')
        ->assertSeeHtml('data-test="receivables-awaiting"')
        ->assertSee('Kitchen — 9 Elm St')
        ->assertSee('recorded by Fran Finance');
});

test('Receivables shows only the Locations the person has Finance for, and only to Finance', function () {
    Livewire::actingAs($this->hana)->test('pages::finance.receivables')
        ->assertDontSee('Whole exterior — 1 Oak St')
        ->assertSee('$0');

    $this->actingAs($this->fran)->get(route('finance.receivables'))->assertOk();
    $this->actingAs($this->pat)->get(route('finance.receivables'))->assertForbidden();
});

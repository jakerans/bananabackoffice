<?php

use App\Actions\Compensation\ManagePay;
use App\Actions\Projects\RecordPayments;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Support\Performance\FinanceBoard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Finance > Performance, step 23b (docs/slice-23-performance-boards-plan.md;
 * decision 179; product-architecture.md, 9.1).
 *
 * It is Friday 20 November 2026 at Lakeview. Finley is Finance for every
 * Location; Fran is Finance at Lakeview only; Sam sells there.
 */

beforeEach(function () {
    $this->travelTo('2026-11-20 15:00:00');
    Storage::fake('files');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->finley = User::factory()->withRoleEverywhere(Role::Finance)->create(['name' => 'Finley Finance']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
});

/**
 * A job with its final invoice of $1,000 sent on a day.
 */
function invoicedOn($test, string $day): Project
{
    $project = soldProject($test->lakeview, $test->sam);
    app(RecordPayments::class)->recordInvoice($project, 'final', 'F-'.$project->id, '1,000', $day, $test->finley);

    return $project->fresh();
}

test('cash collected is the payments received in the month that count', function () {
    $project = invoicedOn($this, '2026-11-02');
    $final = $project->invoices()->sole();
    $record = app(RecordPayments::class);

    $record->recordPayment($project, $final->id, '300', 'card', '2026-11-03', 'ch_1', null, $this->finley);
    $record->recordPayment($project, $final->id, '200', 'check', '2026-11-04', '1001', UploadedFile::fake()->image('Check.jpg'), $this->fran);
    $reversed = $record->recordPayment($project, $final->id, '100', 'card', '2026-11-05', 'ch_2', null, $this->finley);
    $record->reversePayment($project, $reversed->id, 'Charged back.', $this->finley);

    expect(FinanceBoard::for([$this->lakeview->id], '2026-11')->cashCollected())->toBe(['cents' => 30000, 'payments' => 1])
        ->and(FinanceBoard::for([$this->lakeview->id], '2026-10')->cashCollected()['payments'])->toBe(0);
});

test('what is owed is split by age: not due yet, late, and with Collections', function () {
    invoicedOn($this, '2026-11-18');
    invoicedOn($this, '2026-11-05');
    invoicedOn($this, '2026-10-10');

    expect(FinanceBoard::for([$this->lakeview->id], '2026-11')->aging())->toBe(['notDue' => 100000, 'late' => 100000, 'collections' => 100000, 'total' => 300000, 'jobs' => 3]);
});

test('Finance > Performance shows the board; variable pay owed only to those who manage pay', function () {
    invoicedOn($this, '2026-11-18');
    app(ManagePay::class)->adjust($this->sam, 'add', '250', null, 'Spiff.', $this->finley);

    $this->actingAs($this->finley)->get(route('finance.performance'))->assertOk()
        ->assertSeeInOrder(['Owed now', '$1,000'])
        ->assertSeeInOrder(['Variable pay owed', 'Due in open periods', '$250']);

    $this->actingAs($this->fran)->get(route('finance.performance'))->assertOk()
        ->assertSee('Cash collected')
        ->assertDontSee('Variable pay owed');

    $this->actingAs($this->sam)->get(route('finance.performance'))->assertForbidden();
});

<?php

use App\Actions\Marketing\RecordMarketingSpend;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\Role;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\User;
use App\Support\Performance\MarketingBoard;
use App\Support\Performance\MarketingRow;

/*
 * Marketing > Performance, step 25b
 * (docs/slice-25-marketing-and-executive-boards-plan.md; decision 183,
 * questions 4 and 5; product-architecture.md, 4.1).
 *
 * In October 2026 Lakeview spent $1,000 on Google Ads. Two Leads came from
 * it; one was booked with Sam, ran, and sold for $8,450. A third Lead came
 * from a source with no spend.
 */

beforeEach(function () {
    $this->travelTo('2026-10-05 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->casey = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->google = LeadSource::factory()->create(['name' => 'Google Ads']);
    $this->yardSign = LeadSource::factory()->create(['name' => 'Yard sign']);

    app(RecordMarketingSpend::class)->record($this->lakeview, $this->google->id, '2026-10', '1,000', null, $this->manager);

    $sold = bookableLead($this->lakeview, null, ['lead_source_id' => $this->google->id, 'received_at' => now()]);
    bookableLead($this->lakeview, null, ['lead_source_id' => $this->google->id, 'received_at' => now()]);
    bookableLead($this->lakeview, null, ['lead_source_id' => $this->yardSign->id, 'received_at' => now()]);

    bookAppointment($sold, $this->sam, $this->manager);
    $this->travelTo('2026-10-06 19:00:00');
    runAppointment($sold->fresh(), $this->sam);
    $proposal = addProposal($sold->fresh(), $this->sam);
    app(RecordProposalResult::class)->handle($sold->fresh(), $proposal, 'sold', [], $this->sam);

    $this->travelTo('2026-10-26 15:00:00');
});

test('each Lead Source shows its spend, leads, appointments and sales, and what each cost', function () {
    $rows = MarketingBoard::for(collect([$this->lakeview]), '2026-10')->rows()->keyBy('source');
    $google = $rows['Google Ads'];

    expect([$google->spendCents, $google->leads, $google->appointments, $google->sales, $google->soldCents])->toBe([100000, 2, 1, 1, 845000])
        ->and($google->costPerLeadCents())->toBe(50000)
        ->and($google->costPerAppointmentCents())->toBe(100000)
        ->and($google->costPerSaleCents())->toBe(100000)
        ->and($google->returnWords())->toBe('$8.45 per $1')
        ->and($google->closeRate())->toBe(100);

    expect($rows['Yard sign']->leads)->toBe(1)
        ->and($rows['Yard sign']->costPerLeadCents())->toBeNull()
        ->and(MarketingRow::money($rows['Yard sign']->costPerLeadCents()))->toBe('—');
});

test('Marketing > Performance shows the board to those with Marketing access; spend is linked for those who record it', function () {
    $this->actingAs($this->manager)->get(route('marketing.performance', ['month' => '2026-10']))->assertOk()
        ->assertSeeInOrder(['Spend, October 2026', '$1,000', 'Sold revenue', '$8,450', 'Return on spend', '$8.45 per $1'])
        ->assertSeeInOrder(['By Lead Source', 'Google Ads', '$1,000', '2', '$500'])
        ->assertSeeHtml('data-test="go-to-spend"');

    $this->actingAs($this->casey)->get(route('marketing.performance'))->assertOk()->assertDontSeeHtml('data-test="go-to-spend"');
    $this->actingAs($this->sam)->get(route('marketing.performance'))->assertForbidden();
});

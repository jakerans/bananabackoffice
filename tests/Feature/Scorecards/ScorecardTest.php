<?php

use App\Actions\Locations\ChangeSalesTarget;
use App\Actions\Locations\ChangeScorecardSetup;
use App\Actions\Scorecards\SubmitScorecard;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\SaleEntry;
use App\Models\Scorecard;
use App\Models\ScorecardLine;
use App\Models\User;
use App\Support\Scorecards\ScorecardNumbers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The weekly scorecard, step 27a (docs/slice-27-leadership-scorecards-plan.md;
 * decision 187, questions 2 to 7; product-architecture.md, 17.3).
 *
 * It is Monday 26 October 2026. Morgan is Lakeview's general manager; its
 * target is $10,000 a month and Sam sold $8,450 there on 20 October. Riley
 * manages Harbor.
 */

beforeEach(function () {
    $this->travelTo('2026-10-26 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->leader = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Lee Leader']);
    $this->morgan = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->riley = User::factory()->withRoleAt(Role::LocationManager, $this->harbor)->create(['name' => 'Riley Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    app(ChangeSalesTarget::class)->handle($this->lakeview, '10,000', '2026-10', $this->owner);
    scorecardSale($this->lakeview, $this->sam, 8450, '2026-10-20 16:00:00');
});

function scorecardSale(Location $location, User $salesperson, int $dollars, string $at): void
{
    $project = soldProject($location, $salesperson);

    (new SaleEntry)->forceFill([
        'proposal_id' => $project->proposal_id, 'lead_id' => $project->lead_id, 'project_id' => $project->id, 'location_id' => $location->id,
        'salesperson_id' => $salesperson->id, 'salesperson_name' => $salesperson->name, 'kind' => SaleEntry::SALE, 'cents' => $dollars * 100, 'is_lead_win' => true,
        'occurred_at' => $at,
    ])->save();
}

/**
 * @return array{statuses: array<string, string>, notes: array<string, string>}
 */
function allGreen(): array
{
    return ['statuses' => array_fill_keys(array_keys(ScorecardNumbers::KPIS), ScorecardLine::GREEN), 'notes' => []];
}

test('the Owner chooses a Location\'s general manager to own its scorecard, and sets its targets', function () {
    $this->actingAs($this->owner);

    Livewire::test('pages::settings.locations')
        ->call('editScorecard', $this->lakeview->id)
        ->set('scorecardOwnerId', (string) $this->morgan->id)
        ->set('scorecardNsliTarget', '1,500')
        ->set('scorecardCostPerCloseTarget', '300')
        ->call('saveScorecard')
        ->assertHasNoErrors()
        ->assertSee('Morgan Manager');

    expect($this->lakeview->fresh())
        ->scorecard_owner_id->toBe($this->morgan->id)
        ->nsli_target_cents->toBe(150000)
        ->cost_per_close_target_cents->toBe(30000)
        ->and(AuditEvent::query()->where('action', AuditAction::LocationScorecardChanged)->count())->toBe(1);

    expect(fn () => app(ChangeScorecardSetup::class)->handle($this->lakeview, $this->riley->id, null, null, $this->owner))
        ->toThrow(ValidationException::class);
    expect(fn () => app(ChangeScorecardSetup::class)->handle($this->lakeview, $this->morgan->id, '1', null, $this->morgan))
        ->toThrow(AuthorizationException::class);
});

test('its owner sees the week\'s numbers and submits Green, Yellow or Red, with a note for any not Green', function () {
    app(ChangeScorecardSetup::class)->handle($this->lakeview, $this->morgan->id, null, null, $this->owner);
    $this->actingAs($this->morgan);

    // 25 of 31 days of $10,000 is $8,064 due; $8,450 is on target.
    Livewire::test('pages::scorecards.show', ['location' => $this->lakeview->fresh()])
        ->assertSee('Week of 19 to 25 October')
        ->assertSee('$8,450')
        ->assertSee('$8,064 due by the 25th')
        ->assertSee('On target')
        ->set('statuses', [...allGreen()['statuses'], ScorecardNumbers::GROSS_PROFIT => ScorecardLine::YELLOW])
        ->call('submit')
        ->assertHasErrors('notes.'.ScorecardNumbers::GROSS_PROFIT)
        ->set('notes', [ScorecardNumbers::GROSS_PROFIT => 'Two jobs closed; both ran over on prep.'])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('Submitted by Morgan Manager');

    $scorecard = Scorecard::query()->with('lines')->sole();

    expect($scorecard)->month->toBe('2026-10')->owner_name->toBe('Morgan Manager')
        ->and($scorecard->week_starts_on->toDateString())->toBe('2026-10-19')
        ->and($scorecard->lines->pluck('kpi')->all())->toBe(array_keys(ScorecardNumbers::KPIS))
        ->and($scorecard->lines->firstWhere('kpi', ScorecardNumbers::SOLD))
        ->actual->toBe(845000)->target->toBe(1000000)->target_due->toBe(806452)->on_target->toBeTrue()->status->toBe(ScorecardLine::GREEN)
        ->and($scorecard->lines->firstWhere('kpi', ScorecardNumbers::GROSS_PROFIT))
        ->status->toBe(ScorecardLine::YELLOW)->note->toBe('Two jobs closed; both ran over on prep.')
        ->and(AuditEvent::query()->where('action', AuditAction::ScorecardSubmitted)->count())->toBe(1);

    expect(fn () => app(SubmitScorecard::class)->handle($this->lakeview, '2026-10-19', allGreen()['statuses'], [], $this->morgan))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SubmitScorecard::class)->handle($this->lakeview, '2026-10-26', allGreen()['statuses'], [], $this->morgan))
        ->toThrow(ValidationException::class);
});

test('a submitted scorecard keeps its numbers, and its owner corrects a status only with a reason', function () {
    app(ChangeScorecardSetup::class)->handle($this->lakeview, $this->morgan->id, null, null, $this->owner);
    $scorecard = app(SubmitScorecard::class)->handle($this->lakeview, '2026-10-19', allGreen()['statuses'], [], $this->morgan);
    scorecardSale($this->lakeview, $this->sam, 2000, '2026-10-21 16:00:00');
    $line = $scorecard->lines()->where('kpi', ScorecardNumbers::SOLD)->sole();
    $this->actingAs($this->morgan);

    Livewire::test('pages::scorecards.show', ['location' => $this->lakeview->fresh()])
        ->assertSee('$8,450')
        ->assertDontSee('$10,450')
        ->call('openCorrect', $line->id)
        ->set('correctStatus', ScorecardLine::RED)
        ->set('correctNote', 'A $2,000 sale was booked on the wrong day.')
        ->call('saveCorrection')
        ->assertHasErrors('correctReason')
        ->set('correctReason', 'Finance found the sale on the 21st.')
        ->call('saveCorrection')
        ->assertHasNoErrors();

    expect($line->fresh())->status->toBe(ScorecardLine::RED)->actual->toBe(845000)
        ->and(AuditEvent::query()->where('action', AuditAction::ScorecardLineCorrected)->sole())
        ->reason->toBe('Finance found the sale on the 21st.');
});

test('only its owner submits it; the Owner and Leadership see it; nobody else does', function () {
    $this->actingAs($this->owner)->get(route('scorecards.show', $this->lakeview))->assertOk()->assertSee('data-test="submit-scorecard"', false);

    app(ChangeScorecardSetup::class)->handle($this->lakeview, $this->morgan->id, null, null, $this->owner);

    $this->actingAs($this->leader)->get(route('scorecards.show', $this->lakeview))->assertOk()
        ->assertSee('Not submitted yet')
        ->assertDontSee('data-test="submit-scorecard"', false);
    $this->actingAs($this->owner)->get(route('scorecards.show', $this->lakeview))->assertOk()->assertDontSee('data-test="submit-scorecard"', false);
    $this->actingAs($this->riley)->get(route('scorecards.show', $this->lakeview))->assertForbidden();
    $this->actingAs($this->sam)->get(route('scorecards.show', $this->lakeview))->assertForbidden();

    expect(fn () => app(SubmitScorecard::class)->handle($this->lakeview, '2026-10-19', allGreen()['statuses'], [], $this->leader))
        ->toThrow(AuthorizationException::class);
});

test('Home shows its owner the scorecard due, and when it is overdue', function () {
    app(ChangeScorecardSetup::class)->handle($this->lakeview, $this->morgan->id, null, null, $this->owner);

    $this->actingAs($this->morgan)->get(route('dashboard'))->assertOk()
        ->assertSee('Your weekly scorecard')
        ->assertSee('Lakeview, 19 to 25 October')
        ->assertSee('Due by the end of Monday 26 October');

    $this->travelTo('2026-10-27 09:00:00');
    $this->actingAs($this->morgan)->get(route('dashboard'))->assertSee('Overdue');

    $this->actingAs($this->sam)->get(route('dashboard'))->assertDontSee('Your weekly scorecard');
});

<?php

use App\Actions\Attention\RunAttentionSweep;
use App\Actions\Locations\ChangeScorecardSetup;
use App\Actions\Scorecards\SubmitScorecard;
use App\Enums\Role;
use App\Models\AttentionItem;
use App\Models\Location;
use App\Models\ScorecardLine;
use App\Models\User;
use App\Support\Scorecards\ScorecardNumbers;

/*
 * Company > Leadership Scorecards, step 27b
 * (docs/slice-27-leadership-scorecards-plan.md; decision 187, question 7;
 * product-architecture.md, 17.3 and 18).
 *
 * It is Monday 26 October 2026. Morgan owns Lakeview's scorecard and Riley
 * owns Harbor's; Morgan has submitted the week of 19 to 25 October.
 */

beforeEach(function () {
    $this->travelTo('2026-10-26 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->leader = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Lee Leader']);
    $this->morgan = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->riley = User::factory()->withRoleAt(Role::LocationManager, $this->harbor)->create(['name' => 'Riley Manager']);

    app(ChangeScorecardSetup::class)->handle($this->lakeview, $this->morgan->id, null, null, null, $this->owner);
    app(ChangeScorecardSetup::class)->handle($this->harbor, $this->riley->id, null, null, null, $this->owner);

    $this->statuses = array_fill_keys(array_keys(ScorecardNumbers::KPIS), ScorecardLine::GREEN);
    app(SubmitScorecard::class)->handle($this->lakeview, '2026-10-19', [...$this->statuses, ScorecardNumbers::NSLI => ScorecardLine::RED], [ScorecardNumbers::NSLI => 'Too few leads issued this month.'], $this->morgan);
});

test('the Owner and Leadership see every Location\'s week side by side, with who has not submitted', function () {
    $this->actingAs($this->leader)->get(route('company.leadership_scorecards'))
        ->assertOk()
        ->assertSee('19 to 25 October')
        ->assertSeeInOrder(['Harbor', 'Riley Manager', 'Due', 'Lakeview', 'Morgan Manager', 'Submitted', 'Red', 'Too few leads issued this month.']);

    $this->travelTo('2026-10-27 09:00:00');
    $this->actingAs($this->owner)->get(route('company.leadership_scorecards'))
        ->assertOk()
        ->assertSeeInOrder(['Harbor', 'Not submitted', 'Lakeview', 'Submitted']);

    $this->actingAs($this->morgan)->get(route('company.leadership_scorecards'))->assertForbidden();
});

test('a scorecard not submitted by the end of Monday needs its owner\'s attention, until it is submitted', function () {
    app(RunAttentionSweep::class)->handle();
    expect(AttentionItem::query()->where('rule', 'scorecard_missing')->count())->toBe(0);

    $this->travelTo('2026-10-27 09:00:00');
    app(RunAttentionSweep::class)->handle();

    expect(AttentionItem::query()->where('rule', 'scorecard_missing')->whereNull('closed_at')->get())
        ->toHaveCount(1)
        ->sole()->user_id->toBe($this->riley->id)
        ->and(AttentionItem::query()->where('rule', 'scorecard_missing')->sole()->title)->toBe('Harbor\'s scorecard for 19 to 25 October is not submitted.');

    $this->actingAs($this->owner)->get(route('company.needs_attention'))->assertSee('Harbor\'s scorecard for 19 to 25 October is not submitted.');
    $this->actingAs($this->morgan)->get(route('dashboard'))->assertDontSee('Harbor\'s scorecard');

    app(SubmitScorecard::class)->handle($this->harbor, '2026-10-19', $this->statuses, [], $this->riley);
    app(RunAttentionSweep::class)->handle();

    expect(AttentionItem::query()->where('rule', 'scorecard_missing')->whereNull('closed_at')->count())->toBe(0);
});

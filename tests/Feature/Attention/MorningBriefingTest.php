<?php

use App\Enums\Role;
use App\Models\AttentionItem;
use App\Models\Location;
use App\Models\User;
use App\Support\Attention\MorningBriefing;

/*
 * The morning briefing, step 26c (docs/slice-26-needs-attention-plan.md;
 * decision 185, question 7): on Home, a short summary for the person's role,
 * counting from that morning.
 *
 * It is Thursday 5 November 2026 at Lakeview. Sam sells there, Pat runs its
 * jobs and Morgan manages it.
 */

beforeEach(function () {
    $this->travelTo('2026-11-02 17:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->northside = Location::factory()->create(['name' => 'Northside']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->morgan = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
});

/**
 * @return array<string, int>
 */
function briefingLines(User $user, string $title): array
{
    $section = collect(MorningBriefing::for($user)['sections'])->firstWhere('title', $title);

    return collect($section['lines'] ?? [])->mapWithKeys(fn (array $line): array => [$line['label'] => $line['count']])->all();
}

test('a salesperson sees their pending proposals, the appointments to confirm and the visits to close out', function () {
    $proposed = bookableLead($this->lakeview);
    bookAppointment($proposed, $this->sam, $this->morgan, '2026-11-03');
    $waiting = bookableLead($this->lakeview);
    bookAppointment($waiting, $this->sam, $this->morgan, '2026-11-04');

    $this->travelTo('2026-11-05 14:00:00');
    runAppointment($proposed, $this->sam);
    addProposal($proposed, $this->sam);
    bookAppointment(bookableLead($this->lakeview), $this->sam, $this->morgan, '2026-11-08');
    bookAppointment(bookableLead($this->lakeview), $this->sam, $this->morgan, '2026-11-12');

    expect(briefingLines($this->sam, 'Sales'))->toBe([
        'Customers with a pending estimate' => 1,
        'Appointments to confirm, today to 4 days out' => 1,
        'Visits waiting for you to say what happened' => 1,
    ])
        ->and(briefingLines($this->sam, 'Production'))->toBe([])
        ->and(briefingLines($this->sam, 'Your Locations'))->toBe([]);
});

test('a Production Manager sees their own late jobs, job costing, balances and Brand Ambassador items, and what changed since the morning', function () {
    attentionItem($this->pat, $this->lakeview, ['rule' => 'work_running_over', 'subject_key' => 'project:1', 'opened_at' => now()->subDay()]);
    attentionItem($this->pat, $this->lakeview, ['rule' => 'work_running_over', 'subject_key' => 'project:2']);
    attentionItem($this->pat, $this->lakeview, ['rule' => 'balance_past_due', 'subject_key' => 'project:3']);
    attentionItem($this->pat, $this->lakeview, ['rule' => 'job_costing_late', 'subject_key' => 'project:4', 'opened_at' => now()->subDay(), 'closed_at' => now()]);
    attentionItem($this->morgan, $this->lakeview, ['rule' => 'ambassador_overdue', 'subject_key' => 'project:5']);

    expect(briefingLines($this->pat, 'Production'))->toBe([
        'Jobs running over' => 2,
        'Job costing late' => 0,
        'Balances to chase' => 1,
        'Brand Ambassador items overdue' => 0,
    ])
        ->and(MorningBriefing::for($this->pat))->since->toBe(['new' => 2, 'cleared' => 1])->day->toBe('Monday 2 November');
});

test('a manager sees what is open, Urgent, new and cleared at their Locations, each finding once', function () {
    attentionItem($this->pat, $this->lakeview, ['rule' => 'warranty_unowned', 'subject_key' => 'warranty:1', 'severity' => AttentionItem::URGENT]);
    attentionItem($this->morgan, $this->lakeview, ['rule' => 'warranty_unowned', 'subject_key' => 'warranty:1', 'severity' => AttentionItem::URGENT]);
    attentionItem($this->pat, $this->lakeview, ['rule' => 'job_costing_late', 'subject_key' => 'project:2', 'opened_at' => now()->subDays(3)]);
    attentionItem($this->pat, $this->lakeview, ['rule' => 'handoff_missing', 'subject_key' => 'project:3', 'opened_at' => now()->subDays(3), 'closed_at' => now()]);
    attentionItem($this->pat, $this->lakeview, ['rule' => 'handoff_missing', 'subject_key' => 'project:4', 'opened_at' => now()->subDays(3), 'closed_at' => now()->subDay()]);
    attentionItem($this->pat, $this->northside, ['rule' => 'job_costing_late', 'subject_key' => 'project:5']);

    expect(briefingLines($this->morgan, 'Your Locations'))->toBe([
        'Urgent' => 1,
        'Open in all' => 2,
        'New since this morning' => 1,
        'Cleared since this morning' => 1,
    ]);
});

test('Home shows the briefing for the person\'s role', function () {
    $this->actingAs($this->sam)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Your briefing for Monday 2 November')
        ->assertSee('data-test="briefing-sales"', false)
        ->assertDontSee('data-test="briefing-production"', false)
        ->assertSee('Nothing new for you since this morning, none cleared.');

    $this->actingAs($this->morgan)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="briefing-your-locations"', false)
        ->assertDontSee('data-test="briefing-sales"', false);
});

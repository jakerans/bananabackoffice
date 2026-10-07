<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Note;
use App\Models\User;
use Livewire\Livewire;

test('the customer workspace includes only notes and activity from related leads the viewer can currently see', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $actor = User::factory()->create(['name' => 'Alex Recorder']);
    $account = Account::factory()->commercial('Shared Customer')->create();
    $visible = Lead::factory()->at($lakeview)->from($account)->create();
    $hidden = Lead::factory()->at($northside)->from($account)->create();

    Note::factory()->about($visible)->create(['body' => 'Visible customer note']);
    Note::factory()->about($hidden)->create(['body' => 'Northside private note']);

    AuditEvent::factory()->about($visible)->create([
        'actor_id' => $actor->id,
        'actor_name' => $actor->name,
        'location_id' => $lakeview->id,
        'action' => AuditAction::LeadQualified,
    ]);
    AuditEvent::factory()->about($hidden)->create([
        'actor_id' => $actor->id,
        'actor_name' => $actor->name,
        'location_id' => $northside->id,
        'action' => AuditAction::LeadDisqualified,
    ]);

    $this->actingAs($consultant)
        ->get(route('customers.workspace', $account))
        ->assertOk()
        ->assertSee('Visible customer note')
        ->assertSee('Lead qualified')
        ->assertSee('Lakeview')
        ->assertDontSee('Northside private note')
        ->assertDontSee('Lead disqualified')
        ->assertDontSee('Northside');
});

test('the workspace route and later requests recheck current customer access', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $visibleAccount = Account::factory()->create();
    $hiddenAccount = Account::factory()->create();
    $visibleLead = Lead::factory()->at($lakeview)->from($visibleAccount)->create();

    Lead::factory()->at($northside)->from($hiddenAccount)->create();

    $this->actingAs($consultant)
        ->get(route('customers.workspace', $hiddenAccount))
        ->assertForbidden();

    $page = Livewire::actingAs($consultant)->test('pages::customers.workspace', ['account' => $visibleAccount])
        ->set('noteLeadId', (string) $visibleLead->id)
        ->set('noteBody', 'This access is about to end.');

    $consultant->accessGrants()->delete();
    $consultant->refresh();

    $page->call('addNote')->assertForbidden();

    expect(Note::query()->where('body', 'This access is about to end.')->doesntExist())->toBeTrue();
});

test('adding and removing unified notes reuses lead permissions and preserves provenance and removal history', function () {
    $this->freezeSecond();

    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create(['name' => 'Mona Manager']);
    $author = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);
    $account = Account::factory()->commercial('Bakery on Main')->create();
    $visible = Lead::factory()->at($lakeview)->from($account)->create();
    $hidden = Lead::factory()->at($northside)->from($account)->create();
    $existing = Note::factory()->about($visible)->by($author)->create(['body' => 'Call after five.']);

    $page = Livewire::actingAs($manager)->test('pages::customers.workspace', ['account' => $account])
        ->assertSeeInOrder(['Sam Sales', 'Call after five.'])
        ->set('noteLeadId', (string) $visible->id)
        ->set('noteBody', '  Gate code is on the invoice.  ')
        ->call('addNote')
        ->assertHasNoErrors()
        ->assertSet('noteBody', '')
        ->assertSee('Gate code is on the invoice.')
        ->call('openRemoveNote', $existing->id)
        ->call('confirmRemoveNote')
        ->assertHasNoErrors()
        ->assertDontSee('Call after five.')
        ->assertSee('This note was removed from view')
        ->assertSee('Mona Manager');

    $added = Note::query()->where('body', 'Gate code is on the invoice.')->sole();
    $removed = $existing->fresh();

    expect($added->notable->is($visible))->toBeTrue();
    expect($added->author->is($manager))->toBeTrue();
    expect($removed->body)->toBe('Call after five.');
    expect($removed->removedBy->is($manager))->toBeTrue();

    $page->set('noteLeadId', (string) $hidden->id)
        ->set('noteBody', 'Trying another location.')
        ->call('addNote')
        ->assertNotFound();

    expect(Note::query()->where('body', 'Trying another location.')->doesntExist())->toBeTrue();
});

test('activity is a curated chronological feed with provenance and never renders raw audit values', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $actor = User::factory()->create(['name' => 'Riley Recorder']);
    $account = Account::factory()->commercial('Bakery on Main')->create();
    $lead = Lead::factory()->at($lakeview)->from($account)->create();

    AuditEvent::factory()->about($lead)->create([
        'actor_id' => $actor->id,
        'actor_name' => $actor->name,
        'location_id' => $lakeview->id,
        'action' => AuditAction::LeadQualified,
        'values_after' => ['estimated_cost' => 'DO-NOT-SHOW-COST'],
        'reason' => 'DO-NOT-SHOW-REASON',
        'created_at' => now()->subHour(),
    ]);
    AuditEvent::factory()->about($lead)->create([
        'actor_id' => $actor->id,
        'actor_name' => $actor->name,
        'location_id' => $lakeview->id,
        'action' => AuditAction::LeadInquiryCorrected,
        'values_after' => ['marker' => 'DO-NOT-SHOW-CORRECTION'],
        'created_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('customers.workspace', $account))
        ->assertOk()
        ->assertSeeInOrder(['Lead qualified', 'Lakeview', 'Riley Recorder'])
        ->assertDontSee('DO-NOT-SHOW-COST')
        ->assertDontSee('DO-NOT-SHOW-REASON')
        ->assertDontSee('DO-NOT-SHOW-CORRECTION')
        ->assertDontSee('Lead inquiry corrected');
});

test('lead activity is limited by the event location even when the lead is currently visible elsewhere', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $companyWideAgent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $account = Account::factory()->commercial('Transferred Customer')->create();
    $lead = Lead::factory()->at($lakeview)->from($account)->create();

    AuditEvent::factory()->about($account)->create([
        'action' => AuditAction::AccountCreated,
        'actor_name' => 'Customer Recorder',
    ]);
    AuditEvent::factory()->about($lead)->create([
        'action' => AuditAction::LeadUnassigned,
        'location_id' => $northside->id,
        'actor_name' => 'Northside Recorder',
    ]);
    AuditEvent::factory()->about($lead)->create([
        'action' => AuditAction::LeadReceived,
        'location_id' => null,
        'actor_name' => 'Central Recorder',
    ]);
    AuditEvent::factory()->about($lead)->create([
        'action' => AuditAction::LeadAssigned,
        'location_id' => $lakeview->id,
        'actor_name' => 'Lakeview Recorder',
    ]);

    $locationPage = $this->actingAs($consultant)
        ->get(route('customers.workspace', $account))
        ->assertOk()
        ->assertSee('Customer created')
        ->assertSee('Lead assigned')
        ->assertSee('Lakeview Recorder')
        ->assertDontSee('Lead unassigned')
        ->assertDontSee('Northside Recorder')
        ->assertDontSee('Northside')
        ->assertDontSee('Central Recorder');

    expect(substr_count($locationPage->getContent(), 'data-test="customer-activity-event"'))->toBe(2);

    $companyWidePage = $this->actingAs($companyWideAgent)
        ->get(route('customers.workspace', $account))
        ->assertOk()
        ->assertSee('Northside Recorder')
        ->assertSee('Central Recorder')
        ->assertSee('Lakeview Recorder');

    expect(substr_count($companyWidePage->getContent(), 'data-test="customer-activity-event"'))->toBe(4);
});

test('notes and activity use deterministic newest-first pagination', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $location = Location::factory()->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();
    $lead = Lead::factory()->at($location)->from($account)->create();

    foreach (range(1, 11) as $day) {
        Note::factory()->about($lead)->create([
            'body' => match ($day) {
                1 => 'Oldest note',
                11 => 'Newest note',
                default => "Middle note {$day}",
            },
            'created_at' => now()->subDays(11 - $day),
        ]);

        AuditEvent::factory()->about($lead)->create([
            'action' => AuditAction::LeadReceived,
            'actor_name' => match ($day) {
                1 => 'Oldest recorder',
                11 => 'Newest recorder',
                default => "Middle recorder {$day}",
            },
            'created_at' => now()->subDays(11 - $day),
        ]);
    }

    $page = Livewire::actingAs($owner)->test('pages::customers.workspace', ['account' => $account]);

    $page
        ->assertSeeInOrder(['Newest note', 'Middle note 10'])
        ->assertDontSee('Oldest note')
        ->assertSeeInOrder(['Newest recorder', 'Middle recorder 10'])
        ->assertDontSee('Oldest recorder')
        ->call('setPage', 2, 'notesPage')
        ->call('setPage', 2, 'activityPage')
        ->assertSee('Oldest note')
        ->assertSee('Oldest recorder')
        ->assertDontSee('Newest note')
        ->assertDontSee('Newest recorder');
});

test('the workspace returns only to an authorized customer or lead context', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $account = Account::factory()->commercial('Shared Customer')->create();
    $visible = Lead::factory()->at($lakeview)->from($account)->create();
    $hidden = Lead::factory()->at($northside)->from($account)->create();

    $this->actingAs($consultant)
        ->get(route('customers.workspace', ['account' => $account, 'from' => 'lead', 'lead' => $visible->id]))
        ->assertOk()
        ->assertSee('href="'.route('leads.show', $visible).'"', false);

    $this->actingAs($consultant)
        ->get(route('customers.workspace', ['account' => $account, 'from' => 'lead', 'lead' => $hidden->id]))
        ->assertOk()
        ->assertSee('href="'.route('customers.show', $account).'"', false)
        ->assertDontSee('href="'.route('leads.show', $hidden).'"', false);
});

<?php

use App\Actions\Customers\ArchiveAccount;
use App\Actions\Leads\AssignLead;
use App\Actions\Leads\CorrectLeadDisqualification;
use App\Actions\Leads\DisqualifyLead;
use App\Actions\Leads\ReopenLead;
use App\Actions\Leads\ReturnLeadToCentral;
use App\Actions\Leads\RouteLead;
use App\Actions\Leads\StartWorkingLead;
use App\Actions\Locations\DeactivateLocation;
use App\Enums\AssignmentEndReason;
use App\Enums\DisqualificationCategory;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\Property;
use App\Models\User;
use Database\Factories\LeadFactory;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * One of the starting disqualification reasons, by the name it starts with.
 */
function reasonNamed(string $name): LeadDisqualificationReason
{
    return LeadDisqualificationReason::query()->where('name', $name)->sole();
}

beforeEach(function () {
    $this->travelTo('2026-10-06 15:00:00');
});

/*
|--------------------------------------------------------------------------
| Disqualifying
|--------------------------------------------------------------------------
*/

test('a lead can be disqualified from any open status, which ends anyone\'s responsibility for it', function (Closure $state, LeadStatus $from) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = $state(Lead::factory()->at($lakeview)->assignedTo($consultant))->create();
    $reason = reasonNamed('Service Not Offered');

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openDisqualify')
        ->set('disqualificationReasonId', $reason->id)
        ->call('disqualify')
        ->assertHasNoErrors()
        ->assertSee('Disqualified: Service Not Offered')
        // Nothing more for this person to do with it.
        ->assertDontSeeHtml('data-test="disqualify-lead"');

    $disqualified = $lead->fresh();

    expect($disqualified->status)->toBe(LeadStatus::Disqualified);
    expect($disqualified->isActive())->toBeFalse();
    expect($disqualified->disqualificationReason->is($reason))->toBeTrue();
    expect($disqualified->disqualification_category)->toBe(DisqualificationCategory::Serviceability);
    expect($disqualified->last_disqualified_at->equalTo(now()))->toBeTrue();
    expect($disqualified->disqualifiedBy->is($consultant))->toBeTrue();
    expect($disqualified->follow_up_on)->toBeNull();

    // The assignment is closed and kept, and the lead has nobody assigned.
    expect($disqualified->assigned_user_id)->toBeNull();

    $assignment = LeadAssignment::query()->sole();

    expect($assignment->user->is($consultant))->toBeTrue();
    expect($assignment->isOpen())->toBeFalse();
    expect($assignment->end_reason)->toBe(AssignmentEndReason::LeadDisqualified);

    $entry = LeadStatusChange::query()->sole();

    expect($entry->event)->toBe(LeadStatusEvent::Disqualified);
    expect($entry->from_status)->toBe($from);
    expect($entry->to_status)->toBe(LeadStatus::Disqualified);
    expect($entry->changedBy->is($consultant))->toBeTrue();
    expect($entry->location->is($lakeview))->toBeTrue();
    // Who had it at the time, though they no longer do.
    expect($entry->assignee->is($consultant))->toBeTrue();
    expect($entry->disqualificationReason->is($reason))->toBeTrue();
    expect($entry->disqualification_category)->toBe(DisqualificationCategory::Serviceability);
})->with([
    'new' => [fn (LeadFactory $lead) => $lead, LeadStatus::New],
    'working' => [fn (LeadFactory $lead) => $lead->working(), LeadStatus::Working],
    'qualified' => [fn (LeadFactory $lead) => $lead->qualified(), LeadStatus::Qualified],
    'nurture' => [fn (LeadFactory $lead) => $lead->nurture(), LeadStatus::Nurture],
]);

test('disqualifying a lead keeps its inquiry, attribution, services, size and customer', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();

    $account = Account::factory()->withPrimaryContact()->create();
    $property = Property::factory()->of($account)->create();
    $lead = Lead::factory()->at($lakeview)->working()->from($account, $account->primaryContact(), $property)->create(['inquiry_name' => 'Maria Lopez']);

    $before = $lead->fresh()->only(['inquiry_name', 'inquiry_phone', 'lead_channel_id', 'lead_source_id', 'marketing_source_id', 'project_size_id', 'account_id', 'contact_id', 'property_id', 'location_id', 'received_at']);
    $services = $lead->services()->pluck('services.id')->all();

    app(DisqualifyLead::class)->handle($lead, reasonNamed('Outside Service Area'), null, $manager);

    expect($lead->fresh()->only(array_keys($before)))->toEqual($before);
    expect($lead->services()->pluck('services.id')->all())->toBe($services);
    expect($services)->not->toBeEmpty();
});

test('a reason is required, and must be one still offered', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->create();
    $retired = LeadDisqualificationReason::factory()->inactive()->create(['name' => 'Retired Reason']);

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openDisqualify')
        ->assertDontSee('Retired Reason')
        ->call('disqualify')
        ->assertHasErrors('disqualificationReasonId')
        ->set('disqualificationReasonId', $retired->id)
        ->call('disqualify')
        ->assertHasErrors('disqualificationReasonId');

    expect(fn () => app(DisqualifyLead::class)->handle($lead, $retired, null, $manager))
        ->toThrow(ValidationException::class, 'Retired Reason is no longer offered.');

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
});

test('a reason that says little by itself needs an explanation', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->create();

    $page = Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openDisqualify')
        ->set('disqualificationReasonId', reasonNamed('Other')->id)
        ->assertSee('Explanation (required)')
        ->call('disqualify')
        ->assertHasErrors('disqualificationNote');

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);

    $page->set('disqualificationNote', 'A film crew wanting a set painted for one day.')
        ->call('disqualify')
        ->assertHasNoErrors();

    expect($lead->fresh()->disqualification_note)->toBe('A film crew wanting a set painted for one day.');
    expect(LeadStatusChange::query()->sole()->note)->toBe('A film crew wanting a set painted for one day.');
});

test('an already disqualified lead cannot be disqualified again', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->disqualified()->create();

    expect(fn () => app(DisqualifyLead::class)->handle($lead, reasonNamed('Spam / Solicitation'), null, $manager))
        ->toThrow(ValidationException::class, 'This lead is already disqualified.');

    expect($manager->can('changeStatus', $lead))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Unable to Contact
|--------------------------------------------------------------------------
*/

test('unable to contact is refused until the lead has been worked for three days', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create(['work_started_at' => '2026-10-04 15:00:01']);

    $page = Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openDisqualify')
        ->set('disqualificationReasonId', reasonNamed('Unable to Contact')->id)
        ->assertSeeHtml('data-test="unable-to-contact-rule"')
        // The gentler choice is offered right there.
        ->assertSeeHtml('data-test="try-again-later"')
        // A sales consultant is not offered a way round the wait.
        ->assertDontSeeHtml('data-test="waive-contact-wait"')
        ->set('disqualificationNote', 'Called twice, no answer.')
        ->call('disqualify')
        ->assertHasErrors('disqualificationReasonId')
        ->assertSee('can be used from Oct 7, 2026')
        ->assertSee('move it to nurture to try again later');

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
    expect($lead->fresh()->assignee->is($consultant))->toBeTrue();

    // Three days to the second after work started.
    $this->travelTo('2026-10-07 15:00:01');

    $page->call('disqualify')->assertHasNoErrors();

    expect($lead->fresh()->status)->toBe(LeadStatus::Disqualified);
    expect($lead->fresh()->disqualification_note)->toBe('Called twice, no answer.');
});

test('unable to contact needs a note of what was tried', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create(['work_started_at' => now()->subDays(10)]);

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openDisqualify')
        ->set('disqualificationReasonId', reasonNamed('Unable to Contact')->id)
        ->assertSee('What was tried (required)')
        ->call('disqualify')
        ->assertHasErrors(['disqualificationNote' => 'Describe the attempts made to reach them.']);

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
});

test('unable to contact is only for a lead that is being worked', function (Closure $state) {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = $state(Lead::factory()->at($lakeview)->assignedTo($consultant))->create(['work_started_at' => now()->subDays(10)]);
    $status = $lead->status;

    // Not even with the wait waived: nobody has tried to reach them in this status.
    expect(fn () => app(DisqualifyLead::class)->handle($lead, reasonNamed('Unable to Contact'), 'Called twice.', $owner, waiveContactWait: true))
        ->toThrow(ValidationException::class, 'is for a lead that has been worked');

    expect($lead->fresh()->status)->toBe($status);
})->with([
    'new' => [fn (LeadFactory $lead) => $lead],
    'qualified' => [fn (LeadFactory $lead) => $lead->qualified()],
    'nurture' => [fn (LeadFactory $lead) => $lead->nurture()],
]);

test('the wait can be waived by an owner, leadership or a location manager', function (Closure $actor) {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create(['work_started_at' => now()->subHour()]);

    Livewire::actingAs($actor($lakeview))
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openDisqualify')
        ->set('disqualificationReasonId', reasonNamed('Unable to Contact')->id)
        ->assertSeeHtml('data-test="waive-contact-wait"')
        ->set('disqualificationNote', 'The number is disconnected and the email bounced.')
        // Without ticking the box, the wait still applies to them too.
        ->call('disqualify')
        ->assertHasErrors('disqualificationReasonId')
        ->set('waiveContactWait', true)
        ->call('disqualify')
        ->assertHasNoErrors();

    expect($lead->fresh()->status)->toBe(LeadStatus::Disqualified);
})->with([
    'owner' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create()],
    'leadership' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
]);

test('the wait cannot be waived by the call center or a sales consultant, even by asking', function (Closure $actor) {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create(['work_started_at' => now()->subHour()]);

    Livewire::actingAs($actor($lakeview, $consultant))
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openDisqualify')
        ->set('disqualificationReasonId', reasonNamed('Unable to Contact')->id)
        ->assertDontSeeHtml('data-test="waive-contact-wait"')
        ->set('disqualificationNote', 'Called once.')
        // A forged request ticks a box they were never shown.
        ->set('waiveContactWait', true)
        ->call('disqualify')
        ->assertHasErrors('disqualificationReasonId');

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
})->with([
    'call center agent at the location' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create()],
    'company-wide call center agent' => [fn () => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create()],
    'the assigned sales consultant' => [fn (Location $lakeview, User $consultant) => $consultant],
]);

test('a location manager cannot waive the wait at a location they do not manage', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $northside)->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($manager)->create();

    expect($manager->can('changeStatus', $lead))->toBeTrue();
    expect($manager->can('waiveContactWait', $lead))->toBeFalse();
});

test('"try again later" swaps the disqualify dialog for a follow-up', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openDisqualify')
        ->set('disqualificationReasonId', reasonNamed('Unable to Contact')->id)
        ->call('tryAgainLater')
        ->set('followUpOn', '2026-10-09')
        ->set('followUpNote', 'Third attempt, try the evening.')
        ->call('saveNurture')
        ->assertHasNoErrors();

    expect($lead->fresh()->status)->toBe(LeadStatus::Nurture);
});

test('renaming a reason does not remove its rule', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create();

    $reason = reasonNamed('Unable to Contact');
    $reason->update(['name' => 'No Answer']);

    expect(fn () => app(DisqualifyLead::class)->handle($lead, $reason->fresh(), 'Called once.', $consultant))
        ->toThrow(ValidationException::class, '"No Answer" can be used from');
});

/*
|--------------------------------------------------------------------------
| The reporting category is kept as it was
|--------------------------------------------------------------------------
*/

test('recategorizing a reason later does not rewrite a disqualification that already happened', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->create();
    $reason = reasonNamed('Not Authorized to Make Decision');

    app(DisqualifyLead::class)->handle($lead, $reason, null, $manager);

    $reason->update(['category' => DisqualificationCategory::Invalid]);

    expect($lead->fresh()->disqualification_category)->toBe(DisqualificationCategory::LeadQuality);
    expect(LeadStatusChange::query()->sole()->disqualification_category)->toBe(DisqualificationCategory::LeadQuality);

    // A lead disqualified from now on gets the new category.
    $next = Lead::factory()->at($lakeview)->working()->create();

    app(DisqualifyLead::class)->handle($next, $reason->fresh(), null, $manager);

    expect($next->fresh()->disqualification_category)->toBe(DisqualificationCategory::Invalid);
});

/*
|--------------------------------------------------------------------------
| Duplicates
|--------------------------------------------------------------------------
*/

test('a duplicate names the original lead, chosen from leads the person can see', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();

    $original = Lead::factory()->at($lakeview)->working()->create(['inquiry_name' => 'Original Olga', 'inquiry_phone' => '5551230001', 'inquiry_phone_normalized' => '5551230001']);
    $elsewhere = Lead::factory()->at($northside)->create(['inquiry_name' => 'Hidden Hana', 'inquiry_phone' => '5551230001', 'inquiry_phone_normalized' => '5551230001']);
    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Unrelated Una']);

    $lead = Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Repeat Rita', 'inquiry_phone' => '5551230001', 'inquiry_phone_normalized' => '5551230001']);

    $page = Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openDisqualify')
        ->set('disqualificationReasonId', reasonNamed('Duplicate Lead')->id)
        ->assertSeeInOrder(['Which lead does this one repeat?', 'Original Olga'])
        // A lead at a location they cannot see is never offered, and nothing hints that it exists.
        ->assertDontSee('Hidden Hana')
        ->assertDontSee('Northside')
        ->assertDontSee('Unrelated Una');

    // Naming the hidden lead anyway is refused as if it did not exist.
    $page->set('duplicateOfId', $elsewhere->id)->call('disqualify')->assertNotFound();

    expect($lead->fresh()->status)->toBe(LeadStatus::New);

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openDisqualify')
        ->set('disqualificationReasonId', reasonNamed('Duplicate Lead')->id)
        ->set('duplicateOfId', $original->id)
        ->call('disqualify')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['A duplicate of', 'Original Olga']);

    expect($lead->fresh()->duplicateOf->is($original))->toBeTrue();
    expect(LeadStatusChange::query()->sole()->duplicateOf->is($original))->toBeTrue();

    // Neither lead is merged or deleted, and the original is untouched.
    expect(Lead::query()->count())->toBe(4);
    expect($original->fresh()->status)->toBe(LeadStatus::Working);
    expect($lead->fresh()->inquiry_name)->toBe('Repeat Rita');
});

test('a duplicate whose original cannot be named needs an explanation', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    $page = Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openDisqualify')
        ->set('disqualificationReasonId', reasonNamed('Duplicate Lead')->id)
        ->assertSee('The original cannot be identified, or I cannot open it')
        ->call('disqualify')
        ->assertHasErrors('duplicateOfId');

    expect($lead->fresh()->status)->toBe(LeadStatus::New);

    $page->set('disqualificationNote', 'The caller says they already spoke to our Northside office.')
        ->call('disqualify')
        ->assertHasNoErrors();

    expect($lead->fresh()->status)->toBe(LeadStatus::Disqualified);
    expect($lead->fresh()->duplicate_of_lead_id)->toBeNull();
    expect($lead->fresh()->disqualification_note)->toBe('The caller says they already spoke to our Northside office.');
});

test('a lead cannot be a duplicate of itself, and duplicates never form a chain', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $duplicate = reasonNamed('Duplicate Lead');

    $original = Lead::factory()->at($lakeview)->create();
    $copy = Lead::factory()->at($lakeview)->create();
    $another = Lead::factory()->at($lakeview)->create();

    expect(fn () => app(DisqualifyLead::class)->handle($copy, $duplicate, null, $manager, $copy))
        ->toThrow(ValidationException::class, 'A lead cannot be a duplicate of itself.');

    app(DisqualifyLead::class)->handle($copy, $duplicate, null, $manager, $original);

    // Not a duplicate of a duplicate.
    expect(fn () => app(DisqualifyLead::class)->handle($another, $duplicate, null, $manager, $copy->fresh()))
        ->toThrow(ValidationException::class, 'That lead is itself recorded as a duplicate.');

    // And a lead that others repeat cannot itself become a duplicate.
    expect(fn () => app(DisqualifyLead::class)->handle($original, $duplicate, null, $manager, $another))
        ->toThrow(ValidationException::class, 'Another lead is recorded as a duplicate of this one');

    expect($another->fresh()->status)->toBe(LeadStatus::New);
    expect($original->fresh()->status)->toBe(LeadStatus::New);

    // A lead already recorded as a duplicate is not offered as an original either.
    expect(DisqualifyLead::possibleOriginals($another->fresh()->forceFill(['account_id' => null]), $manager)->modelKeys())->not->toContain($copy->id);
});

test('an original is only recorded for the duplicate reason', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $original = Lead::factory()->at($lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    app(DisqualifyLead::class)->handle($lead, reasonNamed('Spam / Solicitation'), null, $manager, $original);

    expect($lead->fresh()->duplicate_of_lead_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Correcting the reason
|--------------------------------------------------------------------------
*/

test('the reason can be corrected while the lead is still disqualified, keeping what was first recorded', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->create();

    app(DisqualifyLead::class)->handle($lead, reasonNamed('Spam / Solicitation'), null, $manager);

    $this->travelTo('2026-10-08 15:00:00');

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openCorrectDisqualification')
        ->assertSet('disqualificationReasonId', reasonNamed('Spam / Solicitation')->id)
        ->set('disqualificationReasonId', reasonNamed('Outside Service Area')->id)
        ->set('disqualificationNote', 'A real inquiry, forty miles outside our area.')
        ->call('disqualify')
        ->assertHasNoErrors()
        ->assertSee('Disqualified: Outside Service Area');

    $corrected = $lead->fresh();

    expect($corrected->status)->toBe(LeadStatus::Disqualified);
    expect($corrected->disqualificationReason->name)->toBe('Outside Service Area');
    expect($corrected->disqualification_category)->toBe(DisqualificationCategory::Serviceability);
    expect($corrected->disqualification_note)->toBe('A real inquiry, forty miles outside our area.');
    // When it was disqualified has not changed.
    expect($corrected->last_disqualified_at->toDateTimeString())->toBe('2026-10-06 15:00:00');

    [$original, $correction] = LeadStatusChange::query()->orderBy('id')->get()->all();

    // The first entry still says what was said first.
    expect($original->event)->toBe(LeadStatusEvent::Disqualified);
    expect($original->disqualificationReason->name)->toBe('Spam / Solicitation');
    expect($original->disqualification_category)->toBe(DisqualificationCategory::Invalid);
    expect($original->created_at->toDateTimeString())->toBe('2026-10-06 15:00:00');

    expect($correction->event)->toBe(LeadStatusEvent::DisqualificationCorrected);
    expect($correction->from_status)->toBe(LeadStatus::Disqualified);
    expect($correction->to_status)->toBe(LeadStatus::Disqualified);
    expect($correction->disqualificationReason->name)->toBe('Outside Service Area');
    expect($correction->disqualification_category)->toBe(DisqualificationCategory::Serviceability);
    expect($correction->note)->toBe('A real inquiry, forty miles outside our area.');
});

test('correcting only the note keeps the category the lead was disqualified under', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->create();
    $reason = reasonNamed('Not Authorized to Make Decision');

    app(DisqualifyLead::class)->handle($lead, $reason, null, $manager);

    $reason->update(['category' => DisqualificationCategory::Invalid]);

    app(CorrectLeadDisqualification::class)->handle($lead->fresh(), $reason->fresh(), 'A tenant, not the owner.', $manager);

    expect($lead->fresh()->disqualification_category)->toBe(DisqualificationCategory::LeadQuality);
    expect(LeadStatusChange::query()->latest('id')->first()->disqualification_category)->toBe(DisqualificationCategory::LeadQuality);
});

test('saving a correction unchanged records nothing', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->create();

    app(DisqualifyLead::class)->handle($lead, reasonNamed('Other'), 'A film set.', $manager);
    app(CorrectLeadDisqualification::class)->handle($lead->fresh(), reasonNamed('Other'), 'A film set.', $manager);

    expect(LeadStatusChange::query()->count())->toBe(1);
});

test('a correction follows the same rules as a disqualification', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->create();

    app(DisqualifyLead::class)->handle($lead, reasonNamed('Spam / Solicitation'), null, $manager);

    expect(fn () => app(CorrectLeadDisqualification::class)->handle($lead->fresh(), reasonNamed('Other'), '  ', $manager))
        ->toThrow(ValidationException::class, 'Explain why');

    expect(fn () => app(CorrectLeadDisqualification::class)->handle($lead->fresh(), reasonNamed('Duplicate Lead'), null, $manager))
        ->toThrow(ValidationException::class, 'Choose the lead this one repeats.');

    expect($lead->fresh()->disqualificationReason->name)->toBe('Spam / Solicitation');
});

test('a lead that is not disqualified has no reason to correct', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->create();

    expect(fn () => app(CorrectLeadDisqualification::class)->handle($lead, reasonNamed('Other'), 'Why.', $manager))
        ->toThrow(ValidationException::class, 'Only a disqualified lead has a reason to correct.');

    expect($manager->can('reopen', $lead))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Reopening
|--------------------------------------------------------------------------
*/

test('the people who hand out leads can reopen a disqualified lead, with a reason', function (Closure $actor) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $original = Lead::factory()->at($lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create();

    app(DisqualifyLead::class)->handle($lead, reasonNamed('Duplicate Lead'), null, $consultant, $original);

    $this->travelTo('2026-10-12 15:00:00');

    $reopener = $actor($lakeview);

    Livewire::actingAs($reopener)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openReopen')
        ->call('reopen')
        ->assertHasErrors('reopenReason')
        ->set('reopenReason', 'They called back: it is a second house, not the same job.')
        ->call('reopen')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="lead-disqualified"');

    $reopened = $lead->fresh();

    // Working, and with nobody assigned: whoever had it is not put back on it.
    expect($reopened->status)->toBe(LeadStatus::Working);
    expect($reopened->assigned_user_id)->toBeNull();
    expect(LeadAssignment::query()->where('lead_id', $lead->id)->whereNull('ended_at')->exists())->toBeFalse();

    // What it was disqualified for is no longer current.
    expect($reopened->disqualification_reason_id)->toBeNull();
    expect($reopened->disqualification_category)->toBeNull();
    expect($reopened->duplicate_of_lead_id)->toBeNull();
    // That it was once disqualified, and when, is kept.
    expect($reopened->last_disqualified_at->toDateTimeString())->toBe('2026-10-06 15:00:00');

    [$disqualified, $reopening] = LeadStatusChange::query()->orderBy('id')->get()->all();

    expect($disqualified->event)->toBe(LeadStatusEvent::Disqualified);
    expect($disqualified->disqualificationReason->name)->toBe('Duplicate Lead');
    expect($disqualified->duplicateOf->is($original))->toBeTrue();
    expect($disqualified->assignee->is($consultant))->toBeTrue();

    expect($reopening->event)->toBe(LeadStatusEvent::Reopened);
    expect($reopening->from_status)->toBe(LeadStatus::Disqualified);
    expect($reopening->to_status)->toBe(LeadStatus::Working);
    expect($reopening->changedBy->is($reopener))->toBeTrue();
    expect($reopening->assigned_user_id)->toBeNull();
    expect($reopening->note)->toBe('They called back: it is a second house, not the same job.');
})->with([
    'owner' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create()],
    'leadership' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'call center agent at the location' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create()],
]);

test('a sales consultant cannot reopen a lead or correct its reason, even one they disqualified', function (string $action) {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->assignedTo($consultant)->create();

    app(DisqualifyLead::class)->handle($lead, reasonNamed('Spam / Solicitation'), null, $consultant);

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertOk()
        ->assertDontSeeHtml('data-test="reopen-lead"')
        ->assertDontSeeHtml('data-test="correct-disqualification"')
        ->call($action)
        ->assertForbidden();

    expect($lead->fresh()->status)->toBe(LeadStatus::Disqualified);
})->with(['openReopen', 'reopen', 'openCorrectDisqualification']);

test('people outside the lead\'s location cannot reopen it', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();
    $lead = Lead::factory()->at($lakeview)->disqualified()->create();

    $elsewhere = User::factory()->withRoleAt(Role::LocationManager, $northside)->create();
    $agentElsewhere = User::factory()->withRoleAt(Role::CallCenterAgent, $northside)->create();

    expect($elsewhere->can('reopen', $lead))->toBeFalse();
    expect($agentElsewhere->can('reopen', $lead))->toBeFalse();
});

test('only a disqualified lead can be reopened', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->working()->create();

    expect(fn () => app(ReopenLead::class)->handle($lead, 'A mistake.', $manager))
        ->toThrow(ValidationException::class, 'Only a disqualified lead can be reopened.');
});

test('a reopened lead can be assigned and worked on to qualification again', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->disqualified()->create();

    app(ReopenLead::class)->handle($lead, 'Disqualified by mistake.', $manager);
    app(AssignLead::class)->handle($lead->fresh(), $consultant, $manager);

    expect($lead->fresh()->assignee->is($consultant))->toBeTrue();
    expect($consultant->can('changeStatus', $lead->fresh()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| A disqualified lead is closed
|--------------------------------------------------------------------------
*/

test('a disqualified lead cannot be routed, returned or assigned until it is reopened', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->disqualified()->create();

    expect($owner->can('assign', $lead))->toBeFalse();
    expect($owner->can('routeAnywhere', $lead))->toBeFalse();
    expect($owner->can('route', [$lead, $northside]))->toBeFalse();
    expect($owner->can('returnToCentral', $lead))->toBeFalse();

    expect(fn () => app(AssignLead::class)->handle($lead, $consultant, $owner))->toThrow(ValidationException::class, 'Reopen it before assigning it.');
    expect(fn () => app(RouteLead::class)->handle($lead, $northside, null, $owner))->toThrow(ValidationException::class, 'Reopen it before sending it anywhere.');
    expect(fn () => app(ReturnLeadToCentral::class)->handle($lead, 'Wrong office.', $owner))->toThrow(ValidationException::class, 'Reopen it before sending it anywhere.');

    expect($lead->fresh()->location->is($lakeview))->toBeTrue();
    expect($lead->fresh()->assigned_user_id)->toBeNull();
});

test('leads in nurture and qualified leads can still be routed, which clears who has them', function (Closure $state) {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = $state(Lead::factory()->at($lakeview)->assignedTo($consultant))->create();
    $status = $lead->status;

    app(RouteLead::class)->handle($lead, $northside, 'The house is on the north side.', $owner);

    // The move changes where it is and who has it, never where it is in its lifecycle.
    expect($lead->fresh()->location->is($northside))->toBeTrue();
    expect($lead->fresh()->assigned_user_id)->toBeNull();
    expect($lead->fresh()->status)->toBe($status);
})->with([
    'nurture' => [fn (LeadFactory $lead) => $lead->nurture()],
    'qualified' => [fn (LeadFactory $lead) => $lead->qualified()],
]);

test('a location with qualified or nurture leads cannot be deactivated, and one with only disqualified leads can', function (Closure $state, bool $blocked) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    $state(Lead::factory()->at($lakeview))->create();

    if ($blocked) {
        expect(fn () => app(DeactivateLocation::class)->handle($lakeview))->toThrow(ValidationException::class, 'Lakeview still has 1 active lead.');
    } else {
        app(DeactivateLocation::class)->handle($lakeview);
    }

    expect($lakeview->fresh()->is_active)->toBe($blocked);
})->with([
    'qualified' => [fn (LeadFactory $lead) => $lead->qualified(), true],
    'nurture' => [fn (LeadFactory $lead) => $lead->nurture(), true],
    'disqualified' => [fn (LeadFactory $lead) => $lead->disqualified(), false],
]);

test('a customer whose only lead is disqualified can be archived', function () {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->withPrimaryContact()->create();
    $lead = Lead::factory()->at($lakeview)->qualified()->from($account, $account->primaryContact())->create();

    expect(fn () => app(ArchiveAccount::class)->archive($account, $owner))->toThrow(ValidationException::class, 'This customer has 1 active lead');

    app(DisqualifyLead::class)->handle($lead, reasonNamed('Service Not Offered'), null, $owner);
    app(ArchiveAccount::class)->archive($account, $owner);

    expect($account->fresh()->isArchived())->toBeTrue();
    // The closed lead keeps pointing at its customer.
    expect($lead->fresh()->account->is($account))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The Leads list
|--------------------------------------------------------------------------
*/

test('all leads hides disqualified leads until that status is chosen', function () {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Lead::factory()->at($lakeview)->working()->create(['inquiry_name' => 'Working Wanda']);
    Lead::factory()->at($lakeview)->qualified()->create(['inquiry_name' => 'Qualified Quinn']);
    Lead::factory()->at($lakeview)->nurture()->create(['inquiry_name' => 'Nurture Nell']);
    Lead::factory()->at($lakeview)->disqualified(reasonNamed('Spam / Solicitation'))->create(['inquiry_name' => 'Closed Clara']);

    Livewire::actingAs($owner)
        ->test('pages::leads.index')
        ->assertSee(['Working Wanda', 'Qualified Quinn', 'Nurture Nell'])
        ->assertDontSee('Closed Clara')
        // Searching does not bring them back either.
        ->set('search', 'Closed')
        ->assertDontSee('Closed Clara')
        ->set('status', LeadStatus::Disqualified->value)
        ->assertSee('Closed Clara')
        ->assertSee('Spam / Solicitation')
        ->set('search', '')
        ->assertDontSee('Working Wanda');
});

test('a disqualified lead leaves the unassigned and unrouted queues', function () {
    $lakeview = Location::factory()->create();
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Lead::factory()->disqualified()->create(['inquiry_name' => 'Unrouted Spam']);
    Lead::factory()->create(['inquiry_name' => 'Unrouted Una']);
    Lead::factory()->at($lakeview)->disqualified()->create(['inquiry_name' => 'Closed Clara']);

    Livewire::actingAs($agent)
        ->test('pages::leads.index')
        ->assertSeeInOrder(['Unrouted', '(1)'])
        ->assertSee('Unrouted Una')
        ->assertDontSee('Unrouted Spam')
        ->call('show', 'unassigned')
        ->assertDontSee('Closed Clara');
});

/*
|--------------------------------------------------------------------------
| The history
|--------------------------------------------------------------------------
*/

test('starting work on a lead is the first entry in its history', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    app(StartWorkingLead::class)->handle($lead, $consultant);

    $entry = LeadStatusChange::query()->sole();

    expect($entry->event)->toBe(LeadStatusEvent::StartedWorking);
    expect($entry->from_status)->toBe(LeadStatus::New);
    expect($entry->to_status)->toBe(LeadStatus::Working);
    expect($entry->changedBy->is($consultant))->toBeTrue();
    expect($entry->assignee->is($consultant))->toBeTrue();
    expect($lead->fresh()->statusChanges)->toHaveCount(1);
});

test('a history entry cannot be changed or deleted', function () {
    $entry = LeadStatusChange::factory()->create();

    expect(fn () => $entry->forceFill(['note' => 'Rewritten.'])->save())->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);

    expect(LeadStatusChange::query()->sole()->note)->toBeNull();
});

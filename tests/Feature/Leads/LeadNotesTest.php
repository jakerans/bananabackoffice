<?php

use App\Actions\Notes\AddNote;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Note;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Adding a note
|--------------------------------------------------------------------------
*/

test('everyone who works leads at a location can add a note to a lead there', function (Closure $user) {
    $this->freezeSecond();

    $lakeview = Location::factory()->create();
    $user = $user($lakeview);
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($user)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertSee('No notes yet.')
        ->set('noteBody', "  Prefers a call after 5 PM.\nGate code is on the invoice.  ")
        ->call('addNote')
        ->assertHasNoErrors()
        ->assertSet('noteBody', '')
        ->assertDontSee('No notes yet.')
        ->assertSee('Prefers a call after 5 PM.')
        ->assertSee($user->name);

    $note = Note::query()->sole();

    expect($note->body)->toBe("Prefers a call after 5 PM.\nGate code is on the invoice.");
    expect($note->notable->is($lead))->toBeTrue();
    expect($note->author->is($user))->toBeTrue();
    expect($note->impersonator_id)->toBeNull();
    expect($note->isRemoved())->toBeFalse();
    expect($note->created_at->equalTo(now()))->toBeTrue();
})->with([
    'owner' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create()],
    'leadership' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'call center agent' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create()],
    'sales consultant' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create()],
]);

test('a sales consultant can add a note to a lead that is not assigned to them', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $colleague = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->assignedTo($colleague)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->set('noteBody', 'She called the office while Roseanne was out.')
        ->call('addNote')
        ->assertHasNoErrors();

    expect(Note::query()->sole()->author->is($consultant))->toBeTrue();
});

test('company-wide lead staff can add a note to an unrouted lead', function () {
    Location::factory()->create();

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $lead = Lead::factory()->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.show', ['lead' => $lead])
        ->set('noteBody', 'Left a voicemail asking which town the property is in.')
        ->call('addNote')
        ->assertHasNoErrors();

    expect(Note::query()->sole()->notable->is($lead))->toBeTrue();
});

test('adding a note changes nothing else about the lead and is not an audit event', function () {
    $lakeview = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    $before = $lead->fresh()->getAttributes();

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->set('noteBody', 'Customer prefers Tuesdays.')
        ->call('addNote')
        ->assertHasNoErrors();

    expect($lead->fresh()->getAttributes())->toBe($before);
    expect($lead->fresh()->status)->toBe(LeadStatus::New);
    expect(AuditEvent::query()->count())->toBe(0);
});

test('a note needs something written, and has a limit', function (string $body) {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->set('noteBody', $body)
        ->call('addNote')
        ->assertHasErrors('noteBody');

    expect(fn () => app(AddNote::class)->handle($lead, $consultant, $body))->toThrow(ValidationException::class);

    expect(Note::query()->count())->toBe(0);
})->with([
    'nothing' => [''],
    'only spaces' => ["  \n  "],
    'too long' => [str_repeat('a', AddNote::MAX_LENGTH + 1)],
]);

test('a note at the limit is accepted', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->set('noteBody', str_repeat('a', AddNote::MAX_LENGTH))
        ->call('addNote')
        ->assertHasNoErrors();

    expect(mb_strlen(Note::query()->sole()->body))->toBe(AddNote::MAX_LENGTH);
});

test('what is written in a note is shown as text, never as markup', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Note::factory()->about($lead)->by($consultant)->create(['body' => '<script>alert("x")</script> <b>urgent</b>']);

    $this->actingAs($consultant)
        ->get(route('leads.show', $lead))
        ->assertOk()
        ->assertSee('<script>alert("x")</script> <b>urgent</b>')
        ->assertDontSee('<script>alert("x")</script>', false)
        ->assertDontSee('<b>urgent</b>', false);
});

/*
|--------------------------------------------------------------------------
| Who may read and add notes: the lead's location decides
|--------------------------------------------------------------------------
*/

test('notes are listed newest first with who wrote them', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create(['name' => 'Mona Manager']);
    $lead = Lead::factory()->at($lakeview)->create();
    $otherLead = Lead::factory()->at($lakeview)->create();

    Note::factory()->about($lead)->by($consultant)->create(['body' => 'Older note', 'created_at' => now()->subDay()]);
    Note::factory()->about($lead)->by($manager)->create(['body' => 'Newer note', 'created_at' => now()->subHour()]);
    Note::factory()->about($otherLead)->by($manager)->create(['body' => 'About a different lead']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertSeeInOrder(['Mona Manager', 'Newer note', 'Sam Sales', 'Older note'])
        ->assertDontSee('About a different lead');
});

test('the notes of a lead the person cannot see are out of reach', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $elsewhere = Lead::factory()->at($northside)->create();
    $unrouted = Lead::factory()->create();

    Note::factory()->about($elsewhere)->create(['body' => 'Northside secret']);
    Note::factory()->about($unrouted)->create(['body' => 'Central secret']);

    foreach ([$elsewhere, $unrouted] as $lead) {
        $this->actingAs($consultant)->get(route('leads.show', $lead))->assertForbidden()->assertDontSee('secret');

        Livewire::actingAs($consultant)->test('pages::leads.show', ['lead' => $lead])->assertForbidden();

        expect($consultant->can('create', [Note::class, $lead]))->toBeFalse();
    }
});

test('a note cannot be added once the person loses access to the lead', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    $page = Livewire::actingAs($consultant)->test('pages::leads.show', ['lead' => $lead]);

    $consultant->accessGrants()->delete();
    $consultant->refresh();

    $page->set('noteBody', 'Trying anyway.')->call('addNote')->assertForbidden();

    expect(Note::query()->count())->toBe(0);
});

test('a role that holds the note permission but cannot see leads cannot add a note to one', function () {
    $lakeview = Location::factory()->create();
    // A Production Manager may write notes (for projects, later) but does not work leads.
    $production = User::factory()->withRoleAt(Role::ProductionManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    expect($production->can('create', [Note::class, $lead]))->toBeFalse();

    Livewire::actingAs($production)->test('pages::leads.show', ['lead' => $lead])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| A note is never edited or deleted
|--------------------------------------------------------------------------
*/

test('a saved note cannot be changed or deleted', function () {
    $note = Note::factory()->create(['body' => 'Customer prefers Tuesdays.']);
    $someoneElse = User::factory()->create();

    expect(fn () => $note->forceFill(['body' => 'Customer prefers Fridays.'])->save())->toThrow(LogicException::class);
    expect(fn () => $note->fresh()->forceFill(['author_id' => $someoneElse->id])->save())->toThrow(LogicException::class);
    expect(fn () => $note->fresh()->delete())->toThrow(LogicException::class);

    $kept = Note::query()->sole();

    expect($kept->body)->toBe('Customer prefers Tuesdays.');
    expect($kept->author_id)->toBe($note->author_id);
});

/*
|--------------------------------------------------------------------------
| Removing a note from view
|--------------------------------------------------------------------------
*/

test('the person a note is from can remove it; it is hidden but kept', function () {
    $this->freezeSecond();

    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();
    $note = Note::factory()->about($lead)->by($consultant)->create(['body' => 'Wrong customer, sorry.']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertSee('Wrong customer, sorry.')
        ->assertSeeHtml('data-test="remove-note"')
        ->call('removeNote', $note->id)
        ->assertHasNoErrors()
        ->assertDontSee('Wrong customer, sorry.')
        ->assertSee('No notes yet.');

    $removed = Note::query()->sole();

    expect($removed->body)->toBe('Wrong customer, sorry.');
    expect($removed->isRemoved())->toBeTrue();
    expect($removed->removed_at->equalTo(now()))->toBeTrue();
    expect($removed->removedBy->is($consultant))->toBeTrue();
});

test('managers can remove anyone\'s note', function (Closure $manager) {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();
    $note = Note::factory()->about($lead)->by($consultant)->create();

    $manager = $manager($lakeview);

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertSeeHtml('data-test="remove-note"')
        ->call('removeNote', $note->id)
        ->assertHasNoErrors();

    expect($note->fresh()->removedBy->is($manager))->toBeTrue();
})->with([
    'owner' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create()],
    'leadership' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
]);

test('other staff cannot remove a note they did not write', function (Role $role) {
    $lakeview = Location::factory()->create();
    $author = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $other = User::factory()->withRoleAt($role, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();
    $note = Note::factory()->about($lead)->by($author)->create(['body' => 'Customer prefers Tuesdays.']);

    Livewire::actingAs($other)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertSee('Customer prefers Tuesdays.')
        ->assertDontSeeHtml('data-test="remove-note"')
        ->call('removeNote', $note->id)
        ->assertForbidden();

    expect($note->fresh()->isRemoved())->toBeFalse();
    expect(AuditEvent::query()->count())->toBe(0);
})->with([
    'sales consultant' => Role::SalesConsultant,
    'call center agent' => Role::CallCenterAgent,
]);

test('an author who no longer works leads there cannot remove their note', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    // Wrote it while at Lakeview; now only works at Northside.
    $author = User::factory()->withRoleAt(Role::SalesConsultant, $northside)->create();
    $lead = Lead::factory()->at($lakeview)->create();
    $note = Note::factory()->about($lead)->by($author)->create();

    expect($author->can('remove', $note))->toBeFalse();
});

test('a manager at another location cannot remove a note', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $manager = User::factory()->withRoleAt(Role::LocationManager, $northside)->create();
    $lead = Lead::factory()->at($lakeview)->create();
    $note = Note::factory()->about($lead)->create();

    expect($manager->can('remove', $note))->toBeFalse();

    // Nor through a lead of their own, by sending the other note's ID.
    $ownLead = Lead::factory()->at($northside)->create();

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $ownLead])
        ->call('removeNote', $note->id)
        ->assertNotFound();

    expect($note->fresh()->isRemoved())->toBeFalse();
});

test('a removed note cannot be removed again, and stays hidden from everyone', function () {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $lead = Lead::factory()->at($lakeview)->create();
    $note = Note::factory()->about($lead)->by($owner)->removed($owner)->create(['body' => 'Removed earlier.']);

    Livewire::actingAs($owner)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertDontSee('Removed earlier.')
        ->assertSee('No notes yet.')
        ->call('removeNote', $note->id)
        ->assertForbidden();

    expect(AuditEvent::query()->count())->toBe(0);
});

test('removing a note is recorded as an audit event about the lead', function () {
    $this->travelTo('2026-10-05 18:30:00');

    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create(['name' => 'Mona Manager']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);
    $lead = Lead::factory()->at($lakeview)->create();
    $note = Note::factory()->about($lead)->by($consultant)->create(['body' => 'Customer prefers Tuesdays.', 'created_at' => '2026-10-04 15:00:00']);

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('removeNote', $note->id)
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::NoteRemoved);
    expect($event->actor->is($manager))->toBeTrue();
    expect($event->subject->is($lead))->toBeTrue();
    expect($event->location->is($lakeview))->toBeTrue();
    expect($event->before()['note_id'])->toBe($note->id);
    expect($event->before()['author'])->toBe('Sam Sales');
    expect($event->before()['written_at'])->toBe('2026-10-04T15:00:00+00:00');
    // What the note said stays on the note itself; the event does not copy it.
    expect(json_encode($event->before()))->not->toContain('Tuesdays');
});

/*
|--------------------------------------------------------------------------
| Notes and impersonation
|--------------------------------------------------------------------------
*/

test('a note written while impersonating names the owner who wrote it as well as the staff identity', function () {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Jacob Ransom']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    impersonate($owner, $consultant);

    $page = $this->get(route('leads.show', $lead))->assertOk();

    sendLivewireAction(livewireSnapshot($page), 'addNote', updates: ['noteBody' => 'Testing the consultant view.'])->assertOk();

    $note = Note::query()->sole();

    expect($note->author->is($consultant))->toBeTrue();
    expect($note->impersonator->is($owner))->toBeTrue();

    // Everyone reading the lead is told the consultant did not write it.
    $this->post(route('impersonation.stop'));
    $this->post(route('logout'));

    $this->actingAs($consultant)
        ->get(route('leads.show', $lead))
        ->assertSee('Testing the consultant view.')
        ->assertSee('Written by Jacob Ransom while viewing Backoffice as Roseanne Smith.');
});

test('removing a note while impersonating records both people', function () {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Jacob Ransom']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);
    $lead = Lead::factory()->at($lakeview)->create();
    $own = Note::factory()->about($lead)->by($consultant)->create();
    $someoneElses = Note::factory()->about($lead)->create();

    impersonate($owner, $consultant);

    $page = livewireSnapshot($this->get(route('leads.show', $lead))->assertOk());

    // As the consultant, only the consultant's own note can be removed: the Owner's reach is not added.
    sendLivewireAction($page, 'removeNote', [$someoneElses->id])->assertForbidden();
    sendLivewireAction($page, 'removeNote', [$own->id])->assertOk();

    expect($someoneElses->fresh()->isRemoved())->toBeFalse();
    expect($own->fresh()->removedBy->is($consultant))->toBeTrue();

    $event = AuditEvent::query()->where('action', AuditAction::NoteRemoved)->sole();

    expect($event->madeBy())->toBe('Jacob Ransom, impersonating Roseanne Smith');
});

test('an ordinary note names nobody but its author', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Note::factory()->about($lead)->by($consultant)->create();

    $this->actingAs($consultant)->get(route('leads.show', $lead))->assertOk()->assertDontSee('while viewing Backoffice as');
});

/*
|--------------------------------------------------------------------------
| Notes stay with the lead
|--------------------------------------------------------------------------
*/

test('notes follow a lead to its new location', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $lakeviewConsultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $northsideConsultant = User::factory()->withRoleAt(Role::SalesConsultant, $northside)->create();
    $lead = Lead::factory()->at($lakeview)->create();
    $note = Note::factory()->about($lead)->by($lakeviewConsultant)->create(['body' => 'Customer prefers Tuesdays.']);

    $lead->forceFill(['location_id' => $northside->id])->save();

    $this->actingAs($northsideConsultant)->get(route('leads.show', $lead))->assertOk()->assertSee('Customer prefers Tuesdays.');
    $this->actingAs($lakeviewConsultant)->get(route('leads.show', $lead))->assertForbidden();

    // Its author can no longer reach it to remove it.
    expect($lakeviewConsultant->fresh()->can('remove', $note->fresh()))->toBeFalse();
});

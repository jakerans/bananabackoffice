<?php

use App\Actions\Attachments\RemoveAttachment;
use App\Actions\Projects\AddPhotos;
use App\Actions\Projects\AwardWorkPackage;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\CompleteProject;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\RecordWork;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Actions\Subcontractors\ChangeSubcontractor;
use App\Actions\Subcontractors\RecordCompliance;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\ComplianceRequirement;
use App\Models\Location;
use App\Models\PunchItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Photos of the work, step 16c (docs/slice-16-document-storage-plan.md;
 * decision 160).
 *
 * At Lakeview, Sandia Brush Co. painted the whole exterior of "Oak St" from
 * 16 to 20 November 2026. It is Monday 23 November; the work is finished.
 * Pat is its Production Manager; Sam sold it; Hal runs production at Harbor.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    Storage::fake('files');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->hal = User::factory()->withRoleAt(Role::ProductionManager, $this->harbor)->create(['name' => 'Hal Harbor']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-14', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-16', '2026-11-27', null, null, $this->pat);
    $this->package = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    app(ChangeWorkPackages::class)->setPayout($this->project, $this->package->id, '4250', null, $this->pat);
    $window = app(ChangeWorkPackages::class)->proposeWindow($this->project, $this->package->id, '2026-11-16', '2026-11-20', $this->pat);

    $this->sandia = app(ChangeSubcontractor::class)->add(['businessName' => 'Sandia Brush Co.', 'contactName' => 'Rosa Alvarez', 'phone' => '505-555-0142', 'locationIds' => [(string) $this->lakeview->id]], $this->pat);
    app(ChangeSubcontractor::class)->moveTo($this->sandia, 'active', null, $this->pat);
    foreach (ComplianceRequirement::query()->whereNotNull('base_key')->get() as $requirement) {
        app(RecordCompliance::class)->handle($this->sandia, $requirement->id, '2026-10-01', $requirement->expires ? '2027-09-30' : null, false, null, $this->pat);
    }

    app(AwardWorkPackage::class)->award($this->project, $this->package->id, $this->sandia->id, $window->id, 'Rosa Alvarez', 'phone', '2026-10-14', $this->pat);
    app(ChangeWorkPackages::class)->confirmWindow($this->project, $window->id, 'Pat Customer', 'phone', '2026-10-14', $this->pat);

    $this->travelTo('2026-11-16 14:00:00');
    app(RecordWork::class)->start($this->project, $this->package->id, '2026-11-16', $this->pat);
    $this->travelTo('2026-11-23 14:00:00');
    app(RecordWork::class)->finish($this->project, $this->package->id, '2026-11-20', $this->pat);
    $this->project = $this->project->fresh();
});

function workPhoto(string $name = 'Front porch.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 40, 30);
}

/**
 * A punch item on Oak St, after the inspection and walkthrough.
 */
function punchItemOnOakSt($test): PunchItem
{
    app(CompleteProject::class)->inspect($test->project, '2026-11-23', 'Pat Production', null, $test->pat);
    app(CompleteProject::class)->walkthrough($test->project, '2026-11-23', 'Pat Production', null, null, $test->pat);

    return app(CompleteProject::class)->addPunchItem($test->project, 'Touch up the garage door trim.', 'subcontractor', $test->pat);
}

/*
|--------------------------------------------------------------------------
| Adding
|--------------------------------------------------------------------------
*/

test('before, progress and after photos are added to a Work Package, and each is in the job\'s history', function () {
    $added = app(AddPhotos::class)->handle($this->package, 'before', [workPhoto(), workPhoto('Garage side.png')], $this->pat);

    expect($added)->toHaveCount(2)
        ->and(collect($added)->pluck('kind')->unique()->all())->toBe(['before'])
        ->and($added[0]->path)->toStartWith('work_package/'.$this->package->id.'/');
    Storage::disk('files')->assertExists($added[0]->path);

    $event = AuditEvent::query()->where('action', AuditAction::AttachmentAdded)->orderBy('id')->first();
    expect($event->subject_type)->toBe('project')
        ->and($event->subject_id)->toBe($this->project->id)
        ->and($event->location_id)->toBe($this->lakeview->id)
        ->and($event->values_after)->toMatchArray(['for' => 'Whole exterior, before', 'kind' => 'before', 'name' => 'Front porch.jpg']);
});

test('the walkthrough takes its photos on the job, and a punch item takes what was found and it fixed', function () {
    $item = punchItemOnOakSt($this);

    app(AddPhotos::class)->handle($this->project, 'walkthrough', [workPhoto('Walkthrough.jpg')], $this->pat);
    app(AddPhotos::class)->handle($item, 'found', [workPhoto('Trim as found.jpg')], $this->pat);
    app(AddPhotos::class)->handle($item, 'fixed', [workPhoto('Trim fixed.jpg')], $this->pat);

    expect($this->project->photos()->pluck('original_name')->all())->toBe(['Walkthrough.jpg'])
        ->and($item->photos()->pluck('kind', 'original_name')->all())->toBe(['Trim as found.jpg' => 'found', 'Trim fixed.jpg' => 'fixed']);

    expect(AuditEvent::query()->where('action', AuditAction::AttachmentAdded)->pluck('values_after')->pluck('for')->all())
        ->toBe(['Walkthrough', 'Punch item "Touch up the garage door trim.", as found', 'Punch item "Touch up the garage door trim.", fixed']);
});

test('only photos are taken, every one checked before any is stored', function () {
    expect(fn () => app(AddPhotos::class)->handle($this->package, 'progress', [workPhoto(), UploadedFile::fake()->create('Invoice.pdf', 40, 'application/pdf')], $this->pat))
        ->toThrow(ValidationException::class, 'Add a JPEG, PNG or HEIC photo.');

    expect(Attachment::query()->count())->toBe(0)
        ->and(Storage::disk('files')->allFiles())->toBe([]);
});

test('what cannot be added is refused', function (string $kind, int $count, string $message) {
    $photos = array_map(fn (int $index): UploadedFile => workPhoto("Photo {$index}.jpg"), range(1, max($count, 1)));

    expect(fn () => app(AddPhotos::class)->handle($this->package, $kind, $count === 0 ? [] : $photos, $this->pat))
        ->toThrow(ValidationException::class, $message);

    expect(Attachment::query()->count())->toBe(0);
})->with([
    'a kind it does not take' => ['walkthrough', 1, 'Choose what the photos show.'],
    'none chosen' => ['before', 0, 'Choose the photos.'],
    'too many at once' => ['before', 21, 'Add up to 20 photos at a time.'],
]);

test('the Production Manager and a manager add photos; nobody else does', function () {
    app(AddPhotos::class)->handle($this->package, 'after', [workPhoto()], $this->manager);

    foreach ([$this->sam, $this->hal] as $person) {
        expect(fn () => app(AddPhotos::class)->handle($this->package, 'after', [workPhoto()], $person))->toThrow(AuthorizationException::class);
    }

    expect($this->package->photos()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Seeing and removing
|--------------------------------------------------------------------------
*/

test('everyone who may see the job opens a photo; nobody else does', function () {
    $photo = app(AddPhotos::class)->handle($this->package, 'progress', [workPhoto()], $this->pat)[0];

    $this->actingAs($this->sam)->get(route('attachments.open', $photo))->assertRedirectContains($photo->path);
    $this->actingAs($this->hal)->get(route('attachments.open', $photo))->assertForbidden();
});

test('the photo strip shows the photos by kind, and only those who may change it get the buttons', function () {
    app(AddPhotos::class)->handle($this->package, 'before', [workPhoto('Porch before.jpg')], $this->pat);
    $after = app(AddPhotos::class)->handle($this->package, 'after', [workPhoto('Porch after.jpg')], $this->pat)[0];

    Livewire::actingAs($this->pat)->test('pages::projects.photos', ['attachable' => $this->package])
        ->assertSeeInOrder(['Before', 'Porch before.jpg', 'After', 'Porch after.jpg'])
        ->assertSeeHtml('src="'.route('attachments.open', $after).'"')
        ->assertSeeHtml('data-test="add-photos"')
        ->assertSeeHtml('data-test="remove-photo"');

    Livewire::actingAs($this->sam)->test('pages::projects.photos', ['attachable' => $this->package])
        ->assertSee('Porch after.jpg')
        ->assertDontSeeHtml('data-test="add-photos"')
        ->assertDontSeeHtml('data-test="remove-photo"');

    Livewire::actingAs($this->hal)->test('pages::projects.photos', ['attachable' => $this->package])->assertForbidden();
});

test('adding from the strip uploads the photos chosen', function () {
    // The clock is in November, so Livewire would clear today's uploads as more than a day old.
    config(['livewire.temporary_file_upload.cleanup' => false]);

    Livewire::actingAs($this->pat)->test('pages::projects.photos', ['attachable' => $this->package])
        ->assertSee('No photos yet.')
        ->call('openAdd')
        ->assertSet('photoKind', 'before')
        ->set('photoKind', 'progress')
        ->set('photos', [workPhoto('Day one.jpg'), workPhoto('Day two.jpg')])
        ->call('savePhotos')
        ->assertHasNoErrors()
        ->assertDispatched('project-photos-changed')
        ->assertSee('Day one.jpg')
        ->assertSee('Day two.jpg');

    expect($this->package->photos()->pluck('kind', 'original_name')->all())->toBe(['Day one.jpg' => 'progress', 'Day two.jpg' => 'progress']);
});

test('removing from the strip needs a reason, and hides the photo at once', function () {
    $photo = app(AddPhotos::class)->handle($this->package, 'before', [workPhoto('Wrong house.jpg')], $this->pat)[0];

    $strip = Livewire::actingAs($this->pat)->test('pages::projects.photos', ['attachable' => $this->package])
        ->call('openRemove', $photo->id)
        ->call('removePhoto')
        ->assertHasErrors(['attachmentReason']);

    $strip->set('photoRemovalReason', 'The wrong house.')
        ->call('removePhoto')
        ->assertHasNoErrors()
        ->assertDontSee('Wrong house.jpg')
        ->assertSee('No photos yet.');

    expect($photo->fresh()->removed_reason)->toBe('The wrong house.');
    expect(fn () => app(RemoveAttachment::class)->handle($photo->fresh(), 'Again.', 'managePhotos', $this->sam))->toThrow(AuthorizationException::class);
});

test('the strip refuses a photo that is not one of its own', function () {
    $item = punchItemOnOakSt($this);
    $elsewhere = app(AddPhotos::class)->handle($item, 'found', [workPhoto()], $this->pat)[0];

    Livewire::actingAs($this->pat)->test('pages::projects.photos', ['attachable' => $this->package])
        ->call('openRemove', $elsewhere->id)
        ->assertStatus(409);
});

/*
|--------------------------------------------------------------------------
| On the job's pages
|--------------------------------------------------------------------------
*/

test('the work, the walkthrough and each punch item carry a photo strip', function () {
    $item = punchItemOnOakSt($this);

    Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $this->project])
        ->assertSeeLivewire('pages::projects.photos');

    Livewire::actingAs($this->pat)->test('pages::projects.completion', ['project' => $this->project->fresh()])
        ->assertSeeLivewire('pages::projects.photos')
        ->assertSee('Touch up the garage door trim.');

    expect($item->photos()->count())->toBe(0);
});

test('the job\'s activity names each photo added and removed', function () {
    $photo = app(AddPhotos::class)->handle($this->package, 'before', [workPhoto()], $this->pat)[0];
    app(RemoveAttachment::class)->handle($photo, 'The wrong house.', 'managePhotos', $this->pat);

    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $this->project])
        ->assertSee('Photo added: Whole exterior, before (Front porch.jpg)')
        ->assertSee('Photo removed: Whole exterior, before (Front porch.jpg)');
});

test('the scorecard\'s progress photos item is ticked when progress photos were added, and says how many', function () {
    $page = fn () => Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $this->project])->call('openScore', $this->package->id);

    $page()->assertSet('scores.progress_photos', false)
        ->assertSee('0 progress photos were added here.');

    app(AddPhotos::class)->handle($this->package, 'progress', [workPhoto(), workPhoto('Day two.jpg')], $this->pat);

    $page()->assertSet('scores.progress_photos', true)
        ->assertSee('2 progress photos were added here.');
});

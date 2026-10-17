<?php

use App\Actions\Attachments\PurgeRemovedAttachments;
use App\Actions\Attachments\RemoveAttachment;
use App\Actions\Subcontractors\AttachComplianceDocument;
use App\Actions\Subcontractors\ChangeSubcontractor;
use App\Actions\Subcontractors\RecordCompliance;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\ComplianceRequirement;
use App\Models\Location;
use App\Models\SubcontractorComplianceRecord;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Compliance documents, step 16b (docs/slice-16-document-storage-plan.md;
 * decision 157, question 4; decision 159).
 *
 * It is Friday 16 October 2026 at Lakeview. Sandia Brush Co. works there.
 * Pat runs production at Lakeview; Hal at Harbor; Sam sells at Lakeview.
 */

beforeEach(function () {
    $this->travelTo('2026-10-16 15:00:00');

    Storage::fake('files');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->hal = User::factory()->withRoleAt(Role::ProductionManager, $this->harbor)->create(['name' => 'Hal Harbor']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    $this->subcontractor = app(ChangeSubcontractor::class)->add([
        'businessName' => 'Sandia Brush Co.', 'contactName' => 'Rosa Alvarez', 'phone' => '505-555-0142',
        'locationIds' => [(string) $this->lakeview->id],
    ], $this->pat);

    $this->base = ComplianceRequirement::query()->whereNotNull('base_key')->pluck('id', 'base_key');
});

function certificatePdf(string $name = 'Liability certificate.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 240, 'application/pdf');
}

/**
 * Record an item for Sandia as Pat, with its document if one is given.
 */
function recordedWithDocument($test, string $baseKey, ?UploadedFile $document = null, bool $coverage = false): SubcontractorComplianceRecord
{
    $expires = in_array($baseKey, ['general_liability', 'workers_comp'], true) ? '2027-06-30' : null;

    return app(RecordCompliance::class)->handle($test->subcontractor, $test->base[$baseKey], '2026-10-01', $expires, $coverage, null, $test->pat, $document);
}

/*
|--------------------------------------------------------------------------
| Attaching
|--------------------------------------------------------------------------
*/

test('an item is recorded with its document in one step, and both are in the subcontractor\'s history', function () {
    $record = recordedWithDocument($this, 'general_liability', certificatePdf());

    $document = $record->documents()->sole();
    expect($document->original_name)->toBe('Liability certificate.pdf')
        ->and($document->kind)->toBe('document')
        ->and($document->path)->toStartWith('subcontractor_compliance_record/'.$record->id.'/')
        ->and($document->path)->not->toContain('Liability');
    Storage::disk('files')->assertExists($document->path);

    $event = AuditEvent::query()->where('action', AuditAction::AttachmentAdded)->sole();
    expect($event->subject_type)->toBe('subcontractor')
        ->and($event->subject_id)->toBe($this->subcontractor->id)
        ->and($event->location_id)->toBe($this->lakeview->id)
        ->and($event->values_after)->toEqual(['for' => 'General liability insurance', 'kind' => 'document', 'name' => 'Liability certificate.pdf', 'size_bytes' => 240 * 1024]);
});

test('a document of the wrong kind refuses the whole recording, and nothing is saved', function () {
    expect(fn () => recordedWithDocument($this, 'agreement', UploadedFile::fake()->create('agreement.docx', 30, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')))
        ->toThrow(ValidationException::class, 'Attach a PDF, or a JPEG, PNG or HEIC photo.');

    expect(SubcontractorComplianceRecord::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0)
        ->and(Storage::disk('files')->allFiles())->toBe([]);
});

test('a document is attached to an item already recorded', function () {
    $record = recordedWithDocument($this, 'agreement');

    app(AttachComplianceDocument::class)->handle($record, UploadedFile::fake()->image('Signed agreement.jpg'), $this->pat);

    expect($record->documents()->sole()->mime_type)->toBe('image/jpeg');
});

test('the W-9 file is never kept, and Banana\'s coverage has no document', function () {
    expect(fn () => recordedWithDocument($this, 'w9', certificatePdf('W-9.pdf')))
        ->toThrow(ValidationException::class, 'The W-9 file is not kept in Backoffice');
    expect(SubcontractorComplianceRecord::query()->count())->toBe(0);

    $w9 = recordedWithDocument($this, 'w9');
    expect(fn () => app(AttachComplianceDocument::class)->handle($w9, certificatePdf('W-9.pdf'), $this->pat))
        ->toThrow(ValidationException::class, 'The W-9 file is not kept in Backoffice');

    $covered = recordedWithDocument($this, 'workers_comp', coverage: true);
    expect(fn () => app(AttachComplianceDocument::class)->handle($covered, certificatePdf(), $this->pat))
        ->toThrow(ValidationException::class, 'They use Banana\'s coverage for this');

    expect(Attachment::query()->count())->toBe(0)
        ->and(Storage::disk('files')->allFiles())->toBe([]);
});

test('renewing takes the new document on the new record; the earlier one stays with the earlier record', function () {
    $first = recordedWithDocument($this, 'general_liability', certificatePdf('2025 certificate.pdf'));

    $this->travelTo('2026-10-20 10:00:00');
    $renewed = recordedWithDocument($this, 'general_liability', certificatePdf('2026 certificate.pdf'));

    expect($first->documents()->pluck('original_name')->all())->toBe(['2025 certificate.pdf'])
        ->and($renewed->documents()->pluck('original_name')->all())->toBe(['2026 certificate.pdf']);

    expect(fn () => app(AttachComplianceDocument::class)->handle($first, certificatePdf(), $this->pat))
        ->toThrow(ValidationException::class, 'This was renewed since.');
});

test('only those who may change the subcontractor attach or remove a document', function (string $who) {
    $record = recordedWithDocument($this, 'general_liability', certificatePdf());

    expect(fn () => app(AttachComplianceDocument::class)->handle($record, certificatePdf(), $this->{$who}))->toThrow(AuthorizationException::class);
    expect(fn () => app(RemoveAttachment::class)->handle($record->documents()->sole(), 'Not theirs.', 'update', $this->{$who}))->toThrow(AuthorizationException::class);

    expect($record->documents()->count())->toBe(1);
})->with(['hal', 'sam']);

/*
|--------------------------------------------------------------------------
| Opening
|--------------------------------------------------------------------------
*/

test('opening a document sends those who may see the subcontractor on by a five-minute link', function () {
    $document = recordedWithDocument($this, 'general_liability', certificatePdf())->documents()->sole();

    $this->actingAs($this->pat)->get(route('attachments.open', $document))
        ->assertRedirectContains($document->path)
        ->assertRedirectContains('expiration='.now()->addMinutes(5)->getTimestamp());
});

test('nobody else may open it, and a removed one is not found', function () {
    $document = recordedWithDocument($this, 'general_liability', certificatePdf())->documents()->sole();

    $this->get(route('attachments.open', $document))->assertRedirect(route('login'));
    $this->actingAs($this->hal)->get(route('attachments.open', $document))->assertForbidden();
    $this->actingAs($this->sam)->get(route('attachments.open', $document))->assertForbidden();

    app(RemoveAttachment::class)->handle($document, 'A newer certificate came.', 'update', $this->pat);

    $this->actingAs($this->pat)->get(route('attachments.open', $document))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

test('the page shows each item\'s document, with a link to open it', function () {
    $record = recordedWithDocument($this, 'general_liability', certificatePdf());
    recordedWithDocument($this, 'w9');
    recordedWithDocument($this, 'agreement');
    $document = $record->documents()->sole();

    Livewire::actingAs($this->pat)->test('pages::production.subcontractor', ['subcontractor' => $this->subcontractor])
        ->assertSeeHtml('href="'.route('attachments.open', $document).'"')
        ->assertSee('Liability certificate.pdf')
        ->assertSee('240 KB')
        ->assertSee('No document attached.')
        ->assertSee('The W-9 file is not kept in Backoffice, as it carries a tax ID number.')
        ->assertSeeHtml('wire:click="openAttachDocument('.$record->id.')"');
});

test('attaching from the page uploads the file and shows it', function () {
    $record = recordedWithDocument($this, 'agreement');

    Livewire::actingAs($this->pat)->test('pages::production.subcontractor', ['subcontractor' => $this->subcontractor])
        ->call('openAttachDocument', $record->id)
        ->call('saveDocument')
        ->assertHasErrors(['complianceDocument'])
        ->set('complianceDocument', certificatePdf('Signed agreement.pdf'))
        ->call('saveDocument')
        ->assertHasNoErrors()
        ->assertSee('Signed agreement.pdf');

    expect($record->documents()->sole()->original_name)->toBe('Signed agreement.pdf');
});

test('recording from the page can take the document at the same time', function () {
    Livewire::actingAs($this->pat)->test('pages::production.subcontractor', ['subcontractor' => $this->subcontractor])
        ->call('openCompliance', $this->base['general_liability'])
        ->set('complianceExpiresOn', '2027-06-30')
        ->set('complianceDocument', certificatePdf())
        ->call('saveCompliance')
        ->assertHasNoErrors()
        ->assertSee('Liability certificate.pdf')
        ->assertSee('General liability insurance: Liability certificate.pdf attached');

    expect(Attachment::query()->sole()->original_name)->toBe('Liability certificate.pdf');
});

test('removing from the page needs a reason, hides it at once, and reads in the history', function () {
    $document = recordedWithDocument($this, 'general_liability', certificatePdf())->documents()->sole();

    $page = Livewire::actingAs($this->pat)->test('pages::production.subcontractor', ['subcontractor' => $this->subcontractor])
        ->call('openRemoveDocument', $document->id)
        ->call('removeDocument')
        ->assertHasErrors(['attachmentReason']);

    expect($document->fresh()->isRemoved())->toBeFalse();

    $page->set('documentRemovalReason', 'Uploaded to the wrong subcontractor.')
        ->call('removeDocument')
        ->assertHasNoErrors()
        ->assertDontSee('href="'.route('attachments.open', $document).'"', false)
        ->assertSee('General liability insurance: Liability certificate.pdf removed')
        ->assertSee('Uploaded to the wrong subcontractor.');

    expect($document->fresh()->isRemoved())->toBeTrue();
});

test('the page refuses a document that is not one of theirs', function () {
    $other = app(ChangeSubcontractor::class)->add([
        'businessName' => 'Mesa Coatings', 'contactName' => 'Lou Baca', 'phone' => '505-555-0199',
        'locationIds' => [(string) $this->lakeview->id],
    ], $this->pat);
    $theirs = app(RecordCompliance::class)->handle($other, $this->base['agreement'], '2026-10-01', null, false, null, $this->pat, certificatePdf());

    Livewire::actingAs($this->pat)->test('pages::production.subcontractor', ['subcontractor' => $this->subcontractor])
        ->call('openRemoveDocument', $theirs->documents()->sole()->id)
        ->assertStatus(409);

    Livewire::actingAs($this->pat)->test('pages::production.subcontractor', ['subcontractor' => $this->subcontractor])
        ->call('openAttachDocument', $theirs->id)
        ->assertStatus(409);
});

test('a manager at their Location sees the link too; someone at another Location does not see the page', function () {
    $leader = User::factory()->withRoleAt(Role::Leadership, $this->lakeview)->create();
    $document = recordedWithDocument($this, 'general_liability', certificatePdf())->documents()->sole();

    Livewire::actingAs($leader)->test('pages::production.subcontractor', ['subcontractor' => $this->subcontractor])
        ->assertSeeHtml('href="'.route('attachments.open', $document).'"');
    $this->actingAs($leader)->get(route('attachments.open', $document))->assertRedirect();

    Livewire::actingAs($this->hal)->test('pages::production.subcontractor', ['subcontractor' => $this->subcontractor])
        ->assertForbidden();
});

test('a purged document reads in the subcontractor\'s history', function () {
    $document = recordedWithDocument($this, 'general_liability', certificatePdf())->documents()->sole();
    app(RemoveAttachment::class)->handle($document, 'A newer certificate came.', 'update', $this->pat);

    $this->travelTo('2026-11-15 15:00:00');
    app(PurgeRemovedAttachments::class)->handle();

    expect(AuditEvent::query()->where('action', AuditAction::AttachmentPurged)->sole()->subject_type)->toBe('subcontractor');

    Livewire::actingAs($this->pat)->test('pages::production.subcontractor', ['subcontractor' => $this->subcontractor])
        ->assertSee('General liability insurance: Liability certificate.pdf deleted from storage, 30 days after it was removed');
});

test('Livewire holds uploads of up to 20 MB', function () {
    expect(config('livewire.temporary_file_upload.rules'))->toBe(['required', 'file', 'max:20480']);
});

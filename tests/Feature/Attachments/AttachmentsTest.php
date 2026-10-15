<?php

use App\Actions\Attachments\AttachFile;
use App\Actions\Attachments\PurgeRemovedAttachments;
use App\Actions\Attachments\RemoveAttachment;
use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Subcontractors\ChangeSubcontractor;
use App\Console\Commands\FilesCheck;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use App\Support\Attachments\AttachmentLink;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * The file storage, step 16a (docs/slice-16-document-storage-plan.md;
 * decision 33; decision 157). Nothing uses it yet but these tests, so a file
 * is attached to a subcontractor, by the subcontractor's own abilities.
 *
 * It is Thursday 15 October 2026 at Lakeview. Pat runs production there; Hal
 * runs it at Harbor, where Sandia Brush Co. does not work.
 */

beforeEach(function () {
    $this->travelTo('2026-10-15 15:00:00');

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
});

/**
 * Attach a file to Sandia, as Pat unless someone else is given.
 */
function attachedToSandia($test, ?UploadedFile $file = null, ?User $by = null): Attachment
{
    return app(AttachFile::class)->handle($test->subcontractor, 'certificate', $file ?? UploadedFile::fake()->create('Liability certificate.pdf', 300, 'application/pdf'), 'update', $by ?? $test->pat);
}

/*
|--------------------------------------------------------------------------
| Attaching
|--------------------------------------------------------------------------
*/

test('a file is stored privately under a random name, with its record and an audit event', function () {
    $attachment = attachedToSandia($this);

    expect($attachment->original_name)->toBe('Liability certificate.pdf')
        ->and($attachment->kind)->toBe('certificate')
        ->and($attachment->mime_type)->toBe('application/pdf')
        ->and($attachment->size_bytes)->toBe(300 * 1024)
        ->and($attachment->uploaded_by_name)->toBe('Pat Production')
        ->and($attachment->disk)->toBe('files')
        ->and($attachment->path)->toMatch('/^subcontractor\/'.$this->subcontractor->id.'\/[0-9a-f-]{36}\.pdf$/')
        ->and($attachment->path)->not->toContain('Liability')
        ->and($attachment->size())->toBe('300 KB');

    Storage::disk('files')->assertExists($attachment->path);
    expect(Storage::disk('files')->allFiles())->toHaveCount(1);

    $event = AuditEvent::query()->where('action', AuditAction::AttachmentAdded)->sole();
    expect($event->subject_type)->toBe('subcontractor')
        ->and($event->actor_name)->toBe('Pat Production')
        ->and($event->values_after)->toEqual(['kind' => 'certificate', 'name' => 'Liability certificate.pdf', 'size_bytes' => 300 * 1024]);
});

test('PDFs, JPEG, PNG and HEIC photos are accepted', function (string $name, string $mime) {
    $attachment = attachedToSandia($this, UploadedFile::fake()->create($name, 50, $mime));

    expect($attachment->mime_type)->toBe($mime);
    Storage::disk('files')->assertExists($attachment->path);
})->with([
    ['Agreement.pdf', 'application/pdf'],
    ['Porch.JPG', 'image/jpeg'],
    ['Porch.jpeg', 'image/jpeg'],
    ['Trim.png', 'image/png'],
    ['IMG_0042.HEIC', 'image/heic'],
]);

test('other kinds of file are refused, judged by what the file is and not only its name', function (string $name, string $mime) {
    expect(fn () => attachedToSandia($this, UploadedFile::fake()->create($name, 50, $mime)))
        ->toThrow(ValidationException::class, 'Attach a PDF, or a JPEG, PNG or HEIC photo.');

    expect(Attachment::query()->count())->toBe(0);
    expect(Storage::disk('files')->allFiles())->toBe([]);
})->with([
    'a script' => ['run.php', 'text/x-php'],
    'a GIF' => ['funny.gif', 'image/gif'],
    'text named as a PDF' => ['certificate.pdf', 'text/plain'],
    'a web page named as a photo' => ['photo.png', 'text/html'],
]);

test('a file over 20 MB is refused', function () {
    expect(fn () => attachedToSandia($this, UploadedFile::fake()->create('Huge.pdf', 20 * 1024 + 1, 'application/pdf')))
        ->toThrow(ValidationException::class, 'The file is too large. Keep it to 20 MB.');

    expect(Attachment::query()->count())->toBe(0);
    expect(Storage::disk('files')->allFiles())->toBe([]);

    expect(attachedToSandia($this, UploadedFile::fake()->create('Just fits.pdf', 20 * 1024, 'application/pdf'))->size())->toBe('20.0 MB');
});

test('only someone who may change what it belongs to can attach a file, and nothing is stored when refused', function (string $who) {
    expect(fn () => attachedToSandia($this, by: $this->{$who}))->toThrow(AuthorizationException::class);

    expect(Attachment::query()->count())->toBe(0);
    expect(Storage::disk('files')->allFiles())->toBe([]);
})->with(['hal', 'sam']);

test('a file is never left in storage without its record', function () {
    $this->mock(RecordAuditEvent::class)->shouldReceive('handle')->andThrow(new RuntimeException('The database went away.'));

    expect(fn () => attachedToSandia($this))->toThrow(RuntimeException::class, 'The database went away.');

    expect(Attachment::query()->count())->toBe(0);
    expect(Storage::disk('files')->allFiles())->toBe([]);
});

test('an attachment record is never deleted', function () {
    $attachment = attachedToSandia($this);

    expect(fn () => $attachment->delete())->toThrow(LogicException::class);
    expect(Attachment::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Opening
|--------------------------------------------------------------------------
*/

test('a link that works for five minutes is given to those who may see what it belongs to', function () {
    $attachment = attachedToSandia($this);

    $link = AttachmentLink::for($attachment, 'view', $this->pat);

    expect($link)->toContain($attachment->path)
        ->toContain('expiration='.now()->addMinutes(5)->getTimestamp());
});

test('nobody else is given a link', function (string $who) {
    $attachment = attachedToSandia($this);

    expect(fn () => AttachmentLink::for($attachment, 'view', $this->{$who}))->toThrow(AuthorizationException::class);
})->with(['hal', 'sam']);

test('a removed file has no link', function () {
    $attachment = attachedToSandia($this);
    app(RemoveAttachment::class)->handle($attachment, 'Uploaded to the wrong subcontractor.', 'update', $this->pat);

    expect(fn () => AttachmentLink::for($attachment->fresh(), 'view', $this->pat))->toThrow(ValidationException::class, 'This file was removed.');
});

/*
|--------------------------------------------------------------------------
| Removing
|--------------------------------------------------------------------------
*/

test('a removed file is hidden at once, with who and why, and stays in storage', function () {
    $attachment = attachedToSandia($this);

    app(RemoveAttachment::class)->handle($attachment, '  Uploaded to the wrong subcontractor.  ', 'update', $this->pat);

    $attachment->refresh();
    expect($attachment->isRemoved())->toBeTrue()
        ->and($attachment->removed_by_name)->toBe('Pat Production')
        ->and($attachment->removed_reason)->toBe('Uploaded to the wrong subcontractor.')
        ->and(Attachment::query()->shown()->count())->toBe(0);

    Storage::disk('files')->assertExists($attachment->path);

    $event = AuditEvent::query()->where('action', AuditAction::AttachmentRemoved)->sole();
    expect($event->reason)->toBe('Uploaded to the wrong subcontractor.')
        ->and($event->values_before)->toEqual(['kind' => 'certificate', 'name' => 'Liability certificate.pdf']);
});

test('removing needs a reason, and the permission, and happens once', function () {
    $attachment = attachedToSandia($this);
    $remove = app(RemoveAttachment::class);

    expect(fn () => $remove->handle($attachment, '   ', 'update', $this->pat))->toThrow(ValidationException::class, 'Say why it is being removed.');
    expect(fn () => $remove->handle($attachment, str_repeat('a', 1001), 'update', $this->pat))->toThrow(ValidationException::class, 'Keep this to 1000 characters or fewer.');
    expect(fn () => $remove->handle($attachment, 'Not mine to remove.', 'update', $this->hal))->toThrow(AuthorizationException::class);
    expect($attachment->fresh()->isRemoved())->toBeFalse();

    $remove->handle($attachment, 'A newer certificate came.', 'update', $this->pat);

    expect(fn () => $remove->handle($attachment, 'Again.', 'update', $this->pat))->toThrow(ValidationException::class, 'This file was removed already.');
    expect(AuditEvent::query()->where('action', AuditAction::AttachmentRemoved)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Purging
|--------------------------------------------------------------------------
*/

test('a removed file is deleted from storage 30 days later, and its record is kept', function () {
    $removed = attachedToSandia($this);
    $kept = attachedToSandia($this, UploadedFile::fake()->create('Agreement.pdf', 80, 'application/pdf'));
    app(RemoveAttachment::class)->handle($removed, 'A newer certificate came.', 'update', $this->pat);

    $this->travelTo('2026-11-14 14:59:00');
    expect(app(PurgeRemovedAttachments::class)->handle())->toBe(['purged' => 0, 'failed' => 0]);
    Storage::disk('files')->assertExists($removed->path);

    $this->travelTo('2026-11-14 15:00:00');
    expect(app(PurgeRemovedAttachments::class)->handle())->toBe(['purged' => 1, 'failed' => 0]);

    Storage::disk('files')->assertMissing($removed->path);
    Storage::disk('files')->assertExists($kept->path);
    expect($removed->fresh()->purged_at?->toDateTimeString())->toBe('2026-11-14 15:00:00')
        ->and($kept->fresh()->purged_at)->toBeNull()
        ->and(Attachment::query()->count())->toBe(2);

    $event = AuditEvent::query()->where('action', AuditAction::AttachmentPurged)->sole();
    expect($event->actor_id)->toBeNull()
        ->and($event->subject_type)->toBe('subcontractor')
        ->and($event->values_before)->toEqual(['kind' => 'certificate', 'name' => 'Liability certificate.pdf']);

    expect(app(PurgeRemovedAttachments::class)->handle())->toBe(['purged' => 0, 'failed' => 0]);
    expect(AuditEvent::query()->where('action', AuditAction::AttachmentPurged)->count())->toBe(1);
});

test('the purge runs each night, and says what it did', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'backoffice:files-purge'));

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('15 3 * * *');
    expect($events->first()->withoutOverlapping)->toBeTrue();

    $this->artisan('backoffice:files-purge')
        ->expectsOutputToContain('Files purge: 0 deleted from storage, 0 to try again tomorrow.')
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| The storage check and restore drill
|--------------------------------------------------------------------------
*/

test('the storage check stores, reads, links and deletes a test file, then names it for the restore drill', function () {
    $this->artisan('backoffice:files-check')
        ->expectsOutputToContain('Stored a test file')
        ->expectsOutputToContain('Read it back')
        ->expectsOutputToContain('Made a short-lived link')
        ->expectsOutputToContain('Deleted it')
        ->expectsOutputToContain('php artisan backoffice:files-check --restored=')
        ->assertSuccessful();

    expect(Storage::disk('files')->allFiles())->toBe([]);
});

test('the restore drill passes only when the file came back whole', function () {
    $name = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d.txt';
    $path = FilesCheck::DIRECTORY.'/'.$name;

    $this->artisan('backoffice:files-check', ['--restored' => 'not-a-name'])->assertFailed();
    $this->artisan('backoffice:files-check', ['--restored' => $name])
        ->expectsOutputToContain('It has not been restored yet.')
        ->assertFailed();

    Storage::disk('files')->put($path, 'Something else');
    $this->artisan('backoffice:files-check', ['--restored' => $name])
        ->expectsOutputToContain('not as it was stored')
        ->assertFailed();

    Storage::disk('files')->put($path, 'Backoffice storage check '.$name."\n");
    $this->artisan('backoffice:files-check', ['--restored' => $name])
        ->expectsOutputToContain('Restore drill passed')
        ->assertSuccessful();

    Storage::disk('files')->assertMissing($path);
});

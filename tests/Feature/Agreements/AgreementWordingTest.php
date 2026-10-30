<?php

use App\Actions\Agreements\ImportPaintScoutAgreement;
use App\Actions\Agreements\SaveAgreementWording;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AgreementWording;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The agreement's wording, step 33a (docs/slice-33-agreement-plan.md;
 * decisions 210 and 211). The sample PaintScout terms are made up: Banana's
 * real contract is imported on the server from a file kept outside the
 * repository.
 */

function importSampleAgreement(): AgreementWording
{
    $settings = json_decode((string) file_get_contents(base_path('tests/Fixtures/paintscout-terms-sample.json')), true);

    return app(ImportPaintScoutAgreement::class)->handle($settings);
}

beforeEach(function () {
    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
});

test('PaintScout\'s contract becomes the first version, in numbered sections of paragraphs and lists', function () {
    $wording = importSampleAgreement();

    expect($wording->only(['version', 'title', 'source', 'created_by']))->toBe([
        'version' => 1,
        'title' => 'SAMPLE PAINTING CUSTOMER AGREEMENT & TERMS',
        'source' => AgreementWording::SOURCE_PAINTSCOUT,
        'created_by' => null,
    ]);

    $sections = $wording->sections()['sections'];

    expect(array_column($sections, 'title'))->toBe(['AGREEMENT AND DOCUMENTS', 'PAYMENT TERMS', 'ACCEPTANCE'])
        ->and($sections[0]['blocks'])->toBe([
            ['type' => 'paragraph', 'text' => 'The Company paints what the accepted Quote lists.'],
            ['type' => 'paragraph', 'text' => 'The agreement consists of:'],
            ['type' => 'list', 'ordered' => true, 'items' => ['The accepted Quote;', 'These terms; and', 'Any signed Change Orders.']],
        ])
        // Emphasis is dropped; the words are kept.
        ->and($sections[1]['blocks'])->toBe([
            ['type' => 'paragraph', 'text' => 'If the Quote states No Deposit, none is due.'],
            ['type' => 'list', 'ordered' => false, 'items' => ['A deposit is due on acceptance.', 'The balance is due on completion.']],
        ]);

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::PricingAgreementWordingSaved)
        ->and($event->actor_name)->toBeNull()
        ->and($event->after())->toMatchArray(['version' => 1, 'sections' => 3, 'source' => 'paintscout']);
});

test('the import command reports the version and refuses the same wording twice, a missing name and an unreadable file', function () {
    $file = base_path('tests/Fixtures/paintscout-terms-sample.json');

    $this->artisan('backoffice:agreement-import', ['file' => $file])
        ->expectsOutputToContain('imported from PaintScout')
        ->assertSuccessful();

    $this->artisan('backoffice:agreement-import', ['file' => $file])
        ->expectsOutputToContain('This is the wording already in use.')
        ->assertFailed();

    $this->artisan('backoffice:agreement-import', ['file' => $file, '--label' => 'NO SUCH TERMS'])
        ->expectsOutputToContain('have none named "NO SUCH TERMS"')
        ->assertFailed();

    $this->artisan('backoffice:agreement-import', ['file' => base_path('tests/Fixtures/no-such-file.json')])->assertFailed();

    expect(AgreementWording::query()->count())->toBe(1);
});

test('a new version leaves the earlier one as it was', function () {
    $first = importSampleAgreement();

    $second = app(SaveAgreementWording::class)->handle('CUSTOMER AGREEMENT', "1. SCOPE\nWhat the Quote lists.", $this->owner, expectedVersion: 1);

    expect($second->only(['version', 'created_by_name', 'source']))->toBe(['version' => 2, 'created_by_name' => 'Olive Owner', 'source' => 'settings'])
        ->and(AgreementWording::current()->is($second))->toBeTrue()
        ->and($first->fresh()->body)->toBe($first->body)
        ->and(AuditEvent::query()->latest('id')->first()->before())->toBe(['version' => 1]);
});

test('a wording without sections, the same wording again, and one changed meanwhile are refused', function (string $title, string $body, ?int $expectedVersion, string $message) {
    importSampleAgreement();
    $current = AgreementWording::current();

    expect(fn () => app(SaveAgreementWording::class)->handle(
        $title === 'same' ? $current->title : $title,
        $body === 'same' ? $current->body : $body,
        $this->owner,
        $expectedVersion,
    ))->toThrow(ValidationException::class, $message);

    expect(AgreementWording::query()->count())->toBe(1);
})->with([
    'no sections' => ['AGREEMENT', 'Only a paragraph.', 1, 'at least one numbered section'],
    'no title' => ['  ', "1. SCOPE\nText.", 1, 'Give the agreement a title'],
    'the same' => ['same', 'same', 1, 'already in use'],
    'changed meanwhile' => ['AGREEMENT', "1. SCOPE\nText.", 0, 'changed the wording meanwhile'],
]);

test('only the Owner and Leadership save a version', function () {
    $manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create();

    expect(fn () => app(SaveAgreementWording::class)->handle('AGREEMENT', "1. SCOPE\nText.", $manager))->toThrow(AuthorizationException::class);

    app(SaveAgreementWording::class)->handle('AGREEMENT', "1. SCOPE\nText.", User::factory()->withRoleEverywhere(Role::Leadership)->create());

    expect(AgreementWording::query()->count())->toBe(1);
});

test('whoever may see prices reads the wording; only the Owner and Leadership may change it', function () {
    importSampleAgreement();

    $this->actingAs($this->owner)->get(route('settings.pricing'))->assertOk()->assertSee(route('settings.agreement'), false);

    $this->actingAs($this->owner)->get(route('settings.agreement'))->assertOk()
        ->assertSeeInOrder(['SAMPLE PAINTING CUSTOMER AGREEMENT &amp; TERMS', '1. AGREEMENT AND DOCUMENTS', '2. PAYMENT TERMS', '3. ACCEPTANCE'], false)
        ->assertSee('href="#agreement-section-2"', false)
        ->assertSee('data-test="change-wording"', false);

    $this->actingAs(User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create())->get(route('settings.agreement'))->assertOk()
        ->assertSee('The accepted Quote;')
        ->assertSee('data-test="view-only"', false)
        ->assertDontSee('data-test="change-wording"', false);

    $this->actingAs(User::factory()->withRoleAt(Role::CallCenterAgent, $this->lakeview)->create())->get(route('settings.agreement'))->assertForbidden();

    Livewire::actingAs(User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create())
        ->test('pages::settings.agreement')
        ->call('startEditing')
        ->assertForbidden();
});

test('from the page, an Owner saves a new version and can still read the earlier one', function () {
    importSampleAgreement();

    Livewire::actingAs($this->owner)->test('pages::settings.agreement')
        ->call('startEditing')
        ->assertSet('editingVersion', 1)
        ->assertSet('wordingTitle', 'SAMPLE PAINTING CUSTOMER AGREEMENT & TERMS')
        ->set('wordingBody', "1. SCOPE\n<script>alert(1)</script>\n- First item")
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editing', false)
        // Shown as words, never run.
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('data-test="wording-version-1"')
        ->set('version', 1)
        ->assertSeeHtml('data-test="earlier-version"')
        ->assertSee('The accepted Quote;');

    expect(AgreementWording::current()->version)->toBe(2);
});

test('the page says when there is no wording yet', function () {
    $this->actingAs($this->owner)->get(route('settings.agreement'))->assertOk()
        ->assertSee('data-test="no-wording"', false)
        ->assertSee('Add the wording');
});

test('the audit log names the version saved', function () {
    importSampleAgreement();

    $this->actingAs($this->owner)->get(route('settings.audit_log', ['area' => 'pricing']))->assertOk()
        ->assertSeeInOrder(['Pricing · Agreement wording saved', 'Agreement wording, version 1'])
        ->assertSee(route('settings.agreement', ['version' => 1]), false);
});

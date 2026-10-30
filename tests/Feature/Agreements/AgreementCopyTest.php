<?php

use App\Actions\Agreements\EmailAgreementCopy;
use App\Actions\Agreements\SignAgreement;
use App\Actions\Proposals\CorrectProposalResult;
use App\Enums\AgreementEmailOutcome;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Mail\SignedAgreementCopy;
use App\Models\Agreement;
use App\Models\AgreementEmail;
use App\Models\AgreementWording;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The customer's emailed copy of the agreement, step 36a
 * (docs/slice-36-agreement-copy-plan.md; decision 214). Made-up wording,
 * prices and addresses only.
 *
 * As in the signing tests: Sam Sales's appointment is on Thursday 8 October
 * 2026 at 3:00 pm Eastern and the customer signs at 3:30 pm. The Lead's
 * address is at Banana's own domain, the one test domain, unless a test
 * says otherwise.
 */

function signTheAgreement(object $test, ?User $by = null): Agreement
{
    return app(SignAgreement::class)->handle(
        $test->lead->fresh(),
        $test->estimate,
        $test->frontOnly,
        'Pat Customer',
        true,
        [array_map(fn (int $step): array => [round(0.1 + $step * 0.05, 2), 0.5], range(0, 11))],
        $by ?? $test->sam,
    );
}

beforeEach(function () {
    Mail::fake();
    config(['mail.customers.enabled' => true, 'mail.customers.test_domains' => ['bananapainting.com']]);

    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales', 'email' => 'sam@bananapainting.com']);

    $this->lead = bookableLead($this->lakeview, attributes: ['inquiry_email' => 'pat.customer@bananapainting.com']);
    bookAppointment($this->lead, $this->sam, $this->manager);
    $this->estimate = estimateFor($this->lead->fresh(), $this->sam, ['Whole exterior' => '8450', 'Front only' => '3200']);
    [, $this->frontOnly] = $this->estimate->options->all();
    AgreementWording::factory()->create();

    $this->travelTo('2026-10-08 19:30:00');
});

test('signing emails the customer their copy from the Location, with replies to the salesperson', function () {
    $agreement = signTheAgreement($this);

    Mail::assertSent(SignedAgreementCopy::class, function (SignedAgreementCopy $mail) use ($agreement): bool {
        $mail->assertFrom('hello@email.bananapainting.com', 'Banana Painting Lakeview')
            ->assertHasReplyTo('sam@bananapainting.com', 'Sam Sales')
            ->assertHasSubject('Your signed agreement with Banana Painting')
            ->assertSeeInOrderInHtml(['Hello Pat Customer,', 'Oct', 'Front only', '$3,200', 'Deposit due on acceptance: $1,600 (50% of the price).', '1. SCOPE OF WORK', 'Signed electronically by Pat Customer on October 8, 2026 at 3:30 PM EDT, version 1 of the wording. Presented by Sam Sales.'])
            ->assertSeeInText('1. SCOPE OF WORK');

        return $mail->mailer === 'customers' && $mail->hasTo('pat.customer@bananapainting.com') && $mail->agreement->is($agreement);
    });

    $email = AgreementEmail::query()->sole();

    expect($email->outcome)->toBe(AgreementEmailOutcome::Sent)
        ->and($email->only(['agreement_id', 'to_address', 'is_again', 'sent_by_name']))->toBe(['agreement_id' => $agreement->id, 'to_address' => 'pat.customer@bananapainting.com', 'is_again' => false, 'sent_by_name' => 'Sam Sales'])
        ->and(AuditEvent::query()->where('action', AuditAction::LeadAgreementEmailed)->sole()->after())->toEqual(['to' => 'pat.customer@bananapainting.com', 'emailed' => 'Yes']);

    $this->actingAs($this->sam)->get(route('leads.agreement', [$this->lead, $agreement]))->assertOk()
        ->assertSee('Emailed to pat.customer@bananapainting.com on Oct 8, 2026 at 3:30 PM.')
        ->assertSee('Email again');

    Livewire::actingAs($this->sam)->test('pages::leads.proposals', ['lead' => $this->lead->fresh()])
        ->assertSee('Copy emailed to pat.customer@bananapainting.com, Oct 8, 2026');
});

test('what the customer typed or was sold is escaped in the email', function () {
    $this->frontOnly->forceFill(['name' => 'Front <b>only</b>'])->save();

    signTheAgreement($this);

    Mail::assertSent(SignedAgreementCopy::class, function (SignedAgreementCopy $mail): bool {
        $mail->assertSeeInHtml('Front &lt;b&gt;only&lt;/b&gt;', false)->assertDontSeeInHtml('<b>only</b>', false);

        return true;
    });
});

test('nothing is sent without an address, before it is switched on, or to a customer address on a server not set to email anyone', function (Closure $arrange, AgreementEmailOutcome $outcome, string $shown) {
    $arrange($this);

    $agreement = signTheAgreement($this);

    Mail::assertNothingSent();

    expect($agreement->fresh()->exists)->toBeTrue()
        ->and(AgreementEmail::query()->sole()->outcome)->toBe($outcome)
        ->and(AuditEvent::query()->where('action', AuditAction::LeadAgreementEmailed)->sole()->after()['emailed'])->toBe('No: '.$outcome->notSentReason());

    $this->actingAs($this->sam)->get(route('leads.agreement', [$this->lead, $agreement]))->assertOk()->assertSee($shown);
})->with([
    'no address' => [fn ($test) => $test->lead->forceFill(['inquiry_email' => null])->save(), AgreementEmailOutcome::NoAddress, 'Not sent on Oct 8, 2026 at 3:30 PM: the lead has no email address.'],
    'switched off' => [fn () => config(['mail.customers.enabled' => false]), AgreementEmailOutcome::SwitchedOff, 'email to customers is not switched on yet'],
    'a customer address on staging, even named production' => [function ($test) {
        app()->detectEnvironment(fn (): string => 'production');
        $test->lead->forceFill(['inquiry_email' => 'pat@example.com'])->save();
    }, AgreementEmailOutcome::TestAddressesOnly, 'this server emails only Banana\'s own addresses'],
]);

test('a customer address at any domain is emailed once the server is set to email anyone', function () {
    $this->lead->forceFill(['inquiry_email' => 'pat@example.com'])->save();
    config(['mail.customers.to_anyone' => true]);

    signTheAgreement($this);

    Mail::assertSent(SignedAgreementCopy::class, fn (SignedAgreementCopy $mail): bool => $mail->hasTo('pat@example.com'));
});

test('an email that cannot be sent leaves the signing in place, and says so', function () {
    Exceptions::fake();
    Mail::shouldReceive('mailer')->with('customers')->andThrow(new RuntimeException('The email service is down.'));

    $agreement = signTheAgreement($this);

    expect(Proposal::query()->sole()->agreement->is($agreement))->toBeTrue()
        ->and(AgreementEmail::query()->sole()->outcome)->toBe(AgreementEmailOutcome::Failed);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The email service is down.');

    $this->actingAs($this->sam)->get(route('leads.agreement', [$this->lead, $agreement]))->assertOk()
        ->assertSee('the email could not be sent')
        ->assertSee('Email a copy');
});

test('the salesperson emails it again after confirming, to the address on the lead now', function () {
    config(['mail.customers.enabled' => false]);
    $agreement = signTheAgreement($this);
    config(['mail.customers.enabled' => true]);
    $this->lead->forceFill(['inquiry_email' => 'pat.new@bananapainting.com'])->save();

    Livewire::actingAs($this->sam)->test('pages::leads.agreement', ['lead' => $this->lead, 'agreement' => $agreement])
        ->assertSee('Email a copy')
        ->assertSee('It goes to pat.new@bananapainting.com, the email address on the lead.')
        ->call('confirmEmailAgain')
        ->call('emailAgain')
        ->assertHasNoErrors()
        ->assertSee('Emailed to pat.new@bananapainting.com');

    Mail::assertSent(SignedAgreementCopy::class, fn (SignedAgreementCopy $mail): bool => $mail->hasTo('pat.new@bananapainting.com'));

    expect(AgreementEmail::query()->latest('id')->first()->only(['to_address', 'is_again', 'sent_by_name']))->toBe(['to_address' => 'pat.new@bananapainting.com', 'is_again' => true, 'sent_by_name' => 'Sam Sales'])
        ->and(AuditEvent::query()->where('action', AuditAction::LeadAgreementEmailed)->latest('id')->first()->after())->toEqual(['to' => 'pat.new@bananapainting.com', 'emailed' => 'Yes', 'again' => 'Yes']);
});

test('it is not emailed again moments after it was sent', function () {
    $agreement = signTheAgreement($this);

    expect(fn () => app(EmailAgreementCopy::class)->again($agreement, $this->sam))
        ->toThrow(ValidationException::class, 'It was emailed a moment ago. Wait a few minutes before sending it again.');

    $this->travel(6)->minutes();
    app(EmailAgreementCopy::class)->again($agreement->fresh(), $this->sam);

    expect(AgreementEmail::query()->where('outcome', AgreementEmailOutcome::Sent)->count())->toBe(2);
});

test('only someone who may sign for the lead emails it again, and never a void agreement', function () {
    $agreement = signTheAgreement($this);
    $this->travel(10)->minutes();
    $other = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create();

    expect(fn () => app(EmailAgreementCopy::class)->again($agreement, $other))->toThrow(AuthorizationException::class);

    app(CorrectProposalResult::class)->handle($this->lead->fresh(), Proposal::query()->sole(), 'The customer chose the whole exterior.', $this->manager);

    expect(fn () => app(EmailAgreementCopy::class)->again($agreement->fresh(), $this->manager))
        ->toThrow(ValidationException::class, 'This agreement is void, so it is not emailed again.');

    $this->actingAs($this->manager)->get(route('leads.agreement', [$this->lead, $agreement]))->assertOk()
        ->assertDontSee('data-test="email-again"', false);

    expect(AgreementEmail::query()->count())->toBe(1);
});

<?php

use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/**
 * Send one message through the in-memory mailer and return what would have gone out.
 *
 * @param  array<string, mixed>  $config  Mail settings this test depends on.
 */
function sentEmail(Closure $send, array $config = []): Email
{
    config([
        'app.name' => 'Banana Backoffice',
        'mail.from' => ['address' => 'backoffice@mail.bananapainting.com', 'name' => 'Banana Backoffice'],
        'mail.reply_to' => ['address' => null, 'name' => null],
        ...$config,
    ]);

    // Mailers are built once and remember their sender, so rebuild them with these settings.
    Mail::forgetMailers();

    $send();

    return Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
}

test('the resend mailer is available and uses the configured api key', function () {
    config(['services.resend.key' => 're_test_not_a_real_key']);

    expect(Mail::mailer('resend')->getSymfonyTransport())->toBeInstanceOf(ResendTransport::class);
});

test('application email is sent from the configured sender to the staff member', function () {
    $staff = User::factory()->invited()->create();

    $email = sentEmail(fn () => $staff->notify(new StaffInvitation('the-token')));

    expect($email->getFrom()[0]->getAddress())->toBe('backoffice@mail.bananapainting.com');
    expect($email->getFrom()[0]->getName())->toBe('Banana Backoffice');
    expect($email->getTo()[0]->getAddress())->toBe($staff->email);
});

test('no reply-to address is added when none is configured', function () {
    $staff = User::factory()->invited()->create();

    $email = sentEmail(fn () => $staff->notify(new StaffInvitation('the-token')));

    expect($email->getReplyTo())->toBe([]);
});

test('replies are directed to a monitored inbox when one is configured', function () {
    $staff = User::factory()->invited()->create();

    $email = sentEmail(
        fn () => $staff->notify(new StaffInvitation('the-token')),
        ['mail.reply_to' => ['address' => 'office@bananapainting.example', 'name' => 'Banana Painting Office']],
    );

    expect($email->getReplyTo()[0]->getAddress())->toBe('office@bananapainting.example');
    expect($email->getReplyTo()[0]->getName())->toBe('Banana Painting Office');
});

test('every transactional email is branded as banana backoffice and never as laravel', function (Closure $notification) {
    $user = User::factory()->create();

    $email = sentEmail(fn () => $user->notify($notification()));

    expect((string) $email->getHtmlBody())->toContain('Banana Backoffice')->not->toContain('Laravel');
    expect((string) $email->getTextBody())->toContain('Banana Backoffice')->not->toContain('Laravel');
    expect($email->getSubject())->not->toContain('Laravel');
})->with([
    'staff invitation' => [fn () => new StaffInvitation('the-token')],
    'password reset' => [fn () => new ResetPassword('the-token')],
    'email verification' => [fn () => new VerifyEmail],
]);

test('the example environment file names the banana sender and contains no api key', function () {
    // The values a new environment starts from cannot be reached through a request, so the file is read directly.
    $example = file_get_contents(base_path('.env.example'));

    expect($example)
        ->toContain('MAIL_FROM_ADDRESS="backoffice@mail.bananapainting.com"')
        ->not->toContain('hello@example.com');

    expect(preg_match('/^RESEND_API_KEY=(.*)$/m', $example, $matches))->toBe(1);
    expect(trim($matches[1]))->toBe('');
});

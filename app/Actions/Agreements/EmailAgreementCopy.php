<?php

namespace App\Actions\Agreements;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AgreementEmailOutcome;
use App\Enums\AuditAction;
use App\Mail\SignedAgreementCopy;
use App\Models\Agreement;
use App\Models\AgreementEmail;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Email the customer their copy of a signed agreement
 * (docs/slice-36-agreement-copy-plan.md, step 36a; decision 214): by itself
 * when they sign, or again by whoever may sign for the Lead.
 *
 * It goes to the Lead's email address as it is now. Nothing is sent until
 * email to customers is switched on (point 1), and outside production only
 * to Banana's own addresses (point 4). Each time is kept, sent or not, with
 * why not, and recorded in the Audit Log. A failure to send never undoes the
 * signing; it is reported and shown.
 */
class EmailAgreementCopy
{
    /** The shortest wait before the same agreement is emailed again. */
    private const int MINUTES_BETWEEN = 5;

    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * When the customer signs.
     */
    public function afterSigning(Agreement $agreement, User $salesperson): AgreementEmail
    {
        return $this->send($agreement, $salesperson, again: false);
    }

    /**
     * Again, from the agreement page.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function again(Agreement $agreement, User $actor): AgreementEmail
    {
        Gate::forUser($actor)->authorize('manageProposals', $agreement->lead);

        if ($agreement->isVoid()) {
            throw ValidationException::withMessages(['email' => __('This agreement is void, so it is not emailed again.')]);
        }

        $lastSent = $agreement->emails()->where('outcome', AgreementEmailOutcome::Sent)->latest('id')->first();

        if ($lastSent !== null && $lastSent->created_at->gt(now()->subMinutes(self::MINUTES_BETWEEN))) {
            throw ValidationException::withMessages(['email' => __('It was emailed a moment ago. Wait a few minutes before sending it again.')]);
        }

        return $this->send($agreement, $actor, again: true);
    }

    /**
     * Whether an address may be emailed on this server: anywhere in
     * production, otherwise only at the test domains.
     */
    public static function mayEmail(string $address): bool
    {
        if (app()->isProduction()) {
            return true;
        }

        $domain = Str::lower(Str::afterLast($address, '@'));

        return in_array($domain, array_map(Str::lower(...), (array) config('mail.customers.test_domains')), true);
    }

    private function send(Agreement $agreement, User $actor, bool $again): AgreementEmail
    {
        $lead = $agreement->lead;
        $address = $lead->inquiry_email === null ? null : trim($lead->inquiry_email);
        $address = $address === '' ? null : $address;

        $outcome = match (true) {
            $address === null => AgreementEmailOutcome::NoAddress,
            ! config('mail.customers.enabled') => AgreementEmailOutcome::SwitchedOff,
            ! self::mayEmail($address) => AgreementEmailOutcome::TestAddressesOnly,
            default => $this->deliver($agreement, $address),
        };

        $email = new AgreementEmail;
        $email->forceFill([
            'agreement_id' => $agreement->id,
            'lead_id' => $lead->id,
            'to_address' => $address,
            'outcome' => $outcome,
            'is_again' => $again,
            'sent_by' => $actor->id,
            'sent_by_name' => $actor->name,
        ])->save();

        $this->recordAuditEvent->handle(
            AuditAction::LeadAgreementEmailed,
            $lead,
            actor: $actor,
            after: array_filter([
                'to' => $address,
                'emailed' => $outcome === AgreementEmailOutcome::Sent ? 'Yes' : 'No: '.$outcome->notSentReason(),
                'again' => $again ? 'Yes' : null,
            ], fn (?string $value): bool => $value !== null),
            location: $lead->location,
        );

        return $email;
    }

    private function deliver(Agreement $agreement, string $address): AgreementEmailOutcome
    {
        // Replies reach the salesperson who presented it, while they still work here.
        $salesperson = $agreement->signed_by === null ? null : User::query()->where('is_active', true)->find($agreement->signed_by);

        try {
            Mail::mailer(config('mail.customers.mailer'))
                ->to([new Address($address, $agreement->signer_name)])
                ->send(new SignedAgreementCopy($agreement, $salesperson));

            return AgreementEmailOutcome::Sent;
        } catch (Throwable $exception) {
            report($exception);

            return AgreementEmailOutcome::Failed;
        }
    }
}

<?php

namespace App\Mail;

use App\Models\Agreement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The customer's copy of the agreement they signed
 * (docs/slice-36-agreement-copy-plan.md, step 36a; decision 214): what they
 * bought, the price and deposit due, the full wording of the version they
 * signed, their name and when. The copy is the email itself (point 3). It is
 * from "Banana Painting" and the Location's name, and replies reach the
 * salesperson who presented it (point 2).
 */
class SignedAgreementCopy extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Agreement $agreement, public ?User $salesperson) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $replyTo = $this->salesperson !== null
            ? [new Address($this->salesperson->email, $this->salesperson->name)]
            : [];

        return new Envelope(
            from: new Address(config('mail.customers.from_address'), $this->companyName()),
            replyTo: $replyTo,
            subject: __('Your signed agreement with :company', ['company' => config('mail.customers.from_name')]),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $details = $this->agreement->details;

        return new Content(
            view: 'mail.signed-agreement-copy',
            text: 'mail.signed-agreement-copy-text',
            with: [
                'details' => $details,
                'wording' => $this->agreement->wording,
                'read' => $this->agreement->wording->sections(),
                'company' => $this->companyName(),
                'signedOn' => $this->agreement->signed_at->copy()->timezone(config('app.display_timezone'))->format('F j, Y \a\t g:i A T'),
                'salespersonName' => $this->salesperson->name ?? $this->agreement->signed_by_name,
                'total' => Money::format($details['total_cents']),
                'deposit' => Money::format($details['deposit_cents']),
                'discounts' => array_map(fn (array $discount): array => ['name' => $discount['name'], 'amount' => Money::format($discount['cents'])], $details['discounts']),
            ],
        );
    }

    /**
     * "Banana Painting" and the Location's name, as kept with the agreement.
     */
    public function companyName(): string
    {
        return trim(config('mail.customers.from_name').' '.$this->agreement->details['location']);
    }
}

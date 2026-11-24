<?php

namespace App\Actions\QuickBooks;

use App\Models\Project;
use App\Models\ProjectInvoice;
use App\Models\ProjectPayment;
use App\Models\QuickBooksConnection;
use App\Support\QuickBooks\PaymentMethods;
use App\Support\QuickBooks\QuickBooksClient;
use App\Support\QuickBooks\QuickBooksNotFound;
use App\Support\QuickBooks\QuickBooksUnavailable;

/**
 * Keep QuickBooks in step with what is recorded in Backoffice against an
 * invoice Backoffice made there (docs/slice-38-quickbooks-tax-plan.md, step
 * 38d; decision 230): a payment taken another way (Square, financing, check,
 * cash) is sent once it counts, taken back out when it is reversed, and a
 * voided invoice is voided there.
 *
 * Cash and check count, and so go, only once Finance approves them
 * (decision 133). Nothing here is asked for by a person directly: each
 * follows a change already authorized and saved in Backoffice.
 */
class SendPaymentToQuickBooks
{
    /** The longest reference QuickBooks keeps on a payment. */
    private const int REFERENCE_LENGTH = 21;

    public function __construct(private QuickBooksClient $client) {}

    /**
     * Straight after a payment is recorded or approved. QuickBooks not
     * answering never undoes it: it is sent the next time QuickBooks is
     * checked.
     */
    public function afterCounting(ProjectPayment $payment): void
    {
        try {
            $this->send($payment);
        } catch (QuickBooksUnavailable $unavailable) {
            report($unavailable);
        }
    }

    /**
     * Straight after a payment is reversed, the same way.
     */
    public function afterReversal(ProjectPayment $payment): void
    {
        try {
            $this->remove($payment);
        } catch (QuickBooksUnavailable $unavailable) {
            report($unavailable);
        }
    }

    /**
     * Record the payment in QuickBooks against its invoice. Returns whether
     * it was sent: not one read from QuickBooks, one already there, one that
     * does not count yet, or one against an invoice QuickBooks does not have.
     *
     * @throws QuickBooksUnavailable
     */
    public function send(ProjectPayment $payment): bool
    {
        $payment = ProjectPayment::query()->with(['invoice', 'project.location', 'project.lead'])->findOrFail($payment->id);
        $invoice = $payment->invoice;
        $customerId = $payment->project->lead->quickbooks_customer_id;

        if ($payment->from_quickbooks || $payment->quickbooks_payment_id !== null || ! $payment->counts() || $invoice->quickbooks_invoice_id === null || $invoice->isVoided() || $customerId === null) {
            return false;
        }

        $connection = $this->connectionFor($payment->project);

        if ($connection === null) {
            return false;
        }

        $methodId = PaymentMethods::idFor($this->client->query($connection, 'PaymentMethod', 'select * from PaymentMethod where Active = true maxresults 100'), $payment->method);

        $kept = $this->client->post($connection, 'payment', array_filter([
            'CustomerRef' => ['value' => $customerId],
            'TotalAmt' => $payment->amount_cents / 100,
            'TxnDate' => $payment->received_on->toDateString(),
            'PaymentRefNum' => $payment->reference === null ? null : mb_substr($payment->reference, 0, self::REFERENCE_LENGTH),
            'PaymentMethodRef' => $methodId === null ? null : ['value' => $methodId],
            'PrivateNote' => self::note($payment),
            'Line' => [[
                'Amount' => $payment->amount_cents / 100,
                'LinkedTxn' => [['TxnId' => $invoice->quickbooks_invoice_id, 'TxnType' => 'Invoice']],
            ]],
        ]))['Payment'] ?? null;

        if (! is_array($kept) || ! isset($kept['Id'])) {
            throw new QuickBooksUnavailable(__('QuickBooks did not keep the payment for :location.', ['location' => $connection->location->name]));
        }

        $payment->forceFill(['quickbooks_payment_id' => (string) $kept['Id']])->save();

        return true;
    }

    /**
     * Take a reversed payment back out of QuickBooks, if Backoffice sent it
     * there. Returns whether there was one to take out.
     *
     * @throws QuickBooksUnavailable
     */
    public function remove(ProjectPayment $payment): bool
    {
        $payment = ProjectPayment::query()->with('project.location')->findOrFail($payment->id);

        if ($payment->from_quickbooks || $payment->quickbooks_payment_id === null || ! $payment->isReversed()) {
            return false;
        }

        $connection = $this->connectionFor($payment->project);

        if ($connection === null) {
            return false;
        }

        try {
            $kept = $this->client->get($connection, 'payment/'.$payment->quickbooks_payment_id)['Payment'] ?? null;
        } catch (QuickBooksNotFound) {
            $kept = null;
        }

        if (is_array($kept)) {
            $this->client->post($connection, 'payment', ['Id' => $kept['Id'], 'SyncToken' => $kept['SyncToken'] ?? '0'], ['operation' => 'delete']);
        }

        $payment->forceFill(['quickbooks_payment_id' => null])->save();

        return true;
    }

    /**
     * Void in QuickBooks an invoice Backoffice made there, before it is
     * voided in Backoffice. One QuickBooks no longer has is done already.
     *
     * @throws QuickBooksUnavailable
     */
    public function voidInvoice(ProjectInvoice $invoice): void
    {
        if ($invoice->quickbooks_invoice_id === null) {
            return;
        }

        $connection = $this->connectionFor($invoice->project)
            ?? throw new QuickBooksUnavailable(__(':location is not connected to QuickBooks, where this invoice is.', ['location' => $invoice->project->location->name]));

        try {
            $kept = $this->client->get($connection, 'invoice/'.$invoice->quickbooks_invoice_id)['Invoice'] ?? null;
        } catch (QuickBooksNotFound) {
            return;
        }

        if (is_array($kept)) {
            $this->client->post($connection, 'invoice', ['Id' => $kept['Id'], 'SyncToken' => $kept['SyncToken'] ?? '0'], ['operation' => 'void']);
        }
    }

    /**
     * The Project's Location's QuickBooks company, when it has one that works.
     */
    public function connectionFor(Project $project): ?QuickBooksConnection
    {
        if (! QuickBooksClient::isConfigured()) {
            return null;
        }

        return QuickBooksConnection::query()->with('location')->where('location_id', $project->location_id)->whereNull('failed_at')->first();
    }

    /**
     * What QuickBooks keeps on a payment Backoffice sent, so the same
     * payment is known again when QuickBooks is read.
     */
    public static function note(ProjectPayment $payment): string
    {
        return 'Backoffice payment #'.$payment->id.' · '.$payment->methodLabel();
    }

    /**
     * The Backoffice payment a QuickBooks payment's note names, if any.
     */
    public static function paymentIdIn(?string $note): ?int
    {
        return preg_match('/Backoffice payment #(\d+)/', (string) $note, $found) === 1 ? (int) $found[1] : null;
    }
}

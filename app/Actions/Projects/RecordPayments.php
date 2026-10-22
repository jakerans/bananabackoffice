<?php

namespace App\Actions\Projects;

use App\Actions\Attachments\AttachFile;
use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\ProjectInvoice;
use App\Models\ProjectPayment;
use App\Models\User;
use App\Support\Money;
use App\Support\Projects\Payments;
use App\Support\Projects\RoughTimeframe;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A Project's invoices and the customer's payments
 * (docs/slice-17-customer-payments-plan.md, step 17a; decision 162;
 * decision 133; product-architecture.md, 9.2 and 10.6).
 *
 * The Production Manager, a manager there, or Finance records the deposit
 * and final invoices as QuickBooks sent them, and each payment against one.
 * Cash and check need a receipt and count once Finance approves them;
 * Finance reverses a wrong payment. A wrong invoice is voided and recorded
 * again. Nothing is changed in place or deleted, and every step is in the
 * Project's history.
 */
class RecordPayments
{
    public function __construct(private RecordAuditEvent $recordAuditEvent, private AttachFile $attachFile) {}

    /**
     * Record the deposit or the final invoice: its QuickBooks number, its
     * total with tax, and the day it was sent (question 2).
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function recordInvoice(Project $project, ?string $kind, ?string $number, ?string $amount, ?string $sentOn, User $actor): ProjectInvoice
    {
        return $this->held($project, $actor, 'recordPayments', function (Project $project) use ($kind, $number, $amount, $sentOn, $actor): ProjectInvoice {
            $kindLabel = ProjectInvoice::KINDS[(string) $kind] ?? throw ValidationException::withMessages(['invoiceKind' => __('Choose the deposit or the final invoice.')]);

            if (Payments::for($project)->invoices->contains('kind', $kind)) {
                throw ValidationException::withMessages(['invoiceKind' => __('This job has its :invoice already. Void it first to record it again.', ['invoice' => mb_strtolower($kindLabel)])]);
            }

            $number = trim((string) $number);

            if ($number === '' || mb_strlen($number) > ProjectInvoice::NUMBER_LENGTH) {
                throw ValidationException::withMessages(['invoiceNumber' => $number === '' ? __('Give the invoice number from QuickBooks.') : __('Keep this to :max characters or fewer.', ['max' => ProjectInvoice::NUMBER_LENGTH])]);
            }

            $cents = Money::toCents($amount)
                ?? throw ValidationException::withMessages(['invoiceAmount' => __('Give the total in dollars, as QuickBooks shows it, like 4,250 or 4,250.50.')]);
            $sent = $this->pastDay($project, $sentOn, 'invoiceSentOn', __('Choose the day it was sent.'), __('The day it was sent cannot be in the future.'));

            $invoice = new ProjectInvoice;
            $invoice->forceFill([
                'project_id' => $project->id,
                'kind' => $kind,
                'number' => $number,
                'amount_cents' => $cents,
                'sent_on' => $sent,
                'recorded_by' => $actor->id,
                'recorded_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectInvoiceRecorded,
                $project,
                actor: $actor,
                after: ['invoice' => $kindLabel, 'number' => $number, 'amount_cents' => $cents, 'sent_on' => $sent->toDateString()],
                location: $project->location,
            );

            return $invoice;
        });
    }

    /**
     * Void an invoice recorded wrongly, with why. Not while a payment against
     * it stands: reverse that first.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function voidInvoice(Project $project, int $invoiceId, ?string $reason, User $actor): ProjectInvoice
    {
        return $this->held($project, $actor, 'recordPayments', function (Project $project) use ($invoiceId, $reason, $actor): ProjectInvoice {
            $invoice = ProjectInvoice::query()->where('project_id', $project->id)->whereNull('voided_at')->find($invoiceId)
                ?? throw ValidationException::withMessages(['invoice' => __('This invoice was changed since. Reload the page.')]);

            if ($invoice->payments()->whereNull('reversed_at')->exists()) {
                throw ValidationException::withMessages(['invoiceReason' => __('A payment stands against this invoice. Finance reverses it first.')]);
            }

            $reason = $this->reason($reason, 'invoiceReason', __('Say why it is voided.'), ProjectInvoice::REASON_LENGTH);

            $invoice->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'voided_by_name' => $actor->name, 'voided_reason' => $reason])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectInvoiceVoided,
                $project,
                actor: $actor,
                before: ['invoice' => $invoice->label(), 'number' => $invoice->number, 'amount_cents' => $invoice->amount_cents],
                location: $project->location,
                reason: $reason,
            );

            return $invoice;
        });
    }

    /**
     * Record money received against an invoice: how much, how, when, and the
     * processor's or QuickBooks' reference. Cash and check need a photo or
     * scan of the receipt, and wait for Finance's approval (question 3).
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function recordPayment(Project $project, int $invoiceId, ?string $amount, ?string $method, ?string $receivedOn, ?string $reference, ?UploadedFile $receipt, User $actor): ProjectPayment
    {
        if ($receipt !== null) {
            AttachFile::ensureAccepted($receipt);
        }

        return $this->held($project, $actor, 'recordPayments', function (Project $project) use ($invoiceId, $amount, $method, $receivedOn, $reference, $receipt, $actor): ProjectPayment {
            $payments = Payments::for($project);
            $invoice = $payments->invoices->firstWhere('id', $invoiceId)
                ?? throw ValidationException::withMessages(['paymentInvoice' => __('Choose the invoice it pays.')]);

            $methodLabel = ProjectPayment::METHODS[(string) $method] ?? throw ValidationException::withMessages(['paymentMethod' => __('Choose how they paid.')]);

            $cents = Money::toCents($amount)
                ?? throw ValidationException::withMessages(['paymentAmount' => __('Give the amount in dollars, like 4,250 or 4,250.50.')]);
            $owed = $invoice->amount_cents - $payments->paidOnCents($invoice, withAwaiting: true);

            if ($cents > $owed) {
                throw ValidationException::withMessages(['paymentAmount' => $owed <= 0
                    ? __('This invoice is paid already.')
                    : __('This is more than the :owed still owed on this invoice.', ['owed' => Money::format($owed)])]);
            }

            $received = $this->pastDay($project, $receivedOn, 'paymentReceivedOn', __('Choose the day it was received.'), __('The day it was received cannot be in the future.'));
            $reference = trim((string) $reference);
            $needsApproval = in_array($method, ProjectPayment::NEEDS_APPROVAL, true);

            if (mb_strlen($reference) > ProjectPayment::REFERENCE_LENGTH) {
                throw ValidationException::withMessages(['paymentReference' => __('Keep this to :max characters or fewer.', ['max' => ProjectPayment::REFERENCE_LENGTH])]);
            }

            if ($reference === '' && ! $needsApproval) {
                throw ValidationException::withMessages(['paymentReference' => __('Give the payment\'s reference from the processor or QuickBooks.')]);
            }

            if ($needsApproval && $receipt === null) {
                throw ValidationException::withMessages(['paymentReceipt' => __('Add a photo or scan of the receipt. Cash and check need it.')]);
            }

            $payment = new ProjectPayment;
            $payment->forceFill([
                'project_id' => $project->id,
                'project_invoice_id' => $invoice->id,
                'amount_cents' => $cents,
                'method' => $method,
                'received_on' => $received,
                'reference' => $reference === '' ? null : $reference,
                'needs_approval' => $needsApproval,
                'recorded_by' => $actor->id,
                'recorded_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectPaymentRecorded,
                $project,
                actor: $actor,
                after: [
                    'invoice' => $invoice->label(),
                    'amount_cents' => $cents,
                    'method' => $methodLabel,
                    'received_on' => $received->toDateString(),
                    'awaiting_approval' => $needsApproval,
                ],
                location: $project->location,
            );

            if ($receipt !== null) {
                $this->attachFile->handle($payment, ProjectPayment::RECEIPT, $receipt, 'recordPayments', $actor);
            }

            return $payment;
        });
    }

    /**
     * Finance approves a cash or check payment, after seeing its receipt: it
     * counts from then on.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function approvePayment(Project $project, int $paymentId, User $actor): ProjectPayment
    {
        return $this->held($project, $actor, 'approvePayments', function (Project $project) use ($paymentId, $actor): ProjectPayment {
            $payment = $this->paymentOf($project, $paymentId);

            if (! $payment->isAwaitingApproval()) {
                throw ValidationException::withMessages(['payment' => __('This payment does not wait for approval.')]);
            }

            $payment->forceFill(['approved_at' => now(), 'approved_by' => $actor->id, 'approved_by_name' => $actor->name])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectPaymentApproved,
                $project,
                actor: $actor,
                after: ['invoice' => $payment->invoice->label(), 'amount_cents' => $payment->amount_cents, 'method' => $payment->methodLabel()],
                location: $project->location,
            );

            return $payment;
        });
    }

    /**
     * Finance reverses a payment recorded wrongly, or one that did not go
     * through, with why. It no longer counts; the record is kept.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function reversePayment(Project $project, int $paymentId, ?string $reason, User $actor): ProjectPayment
    {
        return $this->held($project, $actor, 'approvePayments', function (Project $project) use ($paymentId, $reason, $actor): ProjectPayment {
            $payment = $this->paymentOf($project, $paymentId);
            $reason = $this->reason($reason, 'paymentReason', __('Say why it is reversed.'), ProjectPayment::REASON_LENGTH);

            $payment->forceFill(['reversed_at' => now(), 'reversed_by' => $actor->id, 'reversed_by_name' => $actor->name, 'reversed_reason' => $reason])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectPaymentReversed,
                $project,
                actor: $actor,
                before: ['invoice' => $payment->invoice->label(), 'amount_cents' => $payment->amount_cents, 'method' => $payment->methodLabel()],
                location: $project->location,
                reason: $reason,
            );

            return $payment;
        });
    }

    private function paymentOf(Project $project, int $paymentId): ProjectPayment
    {
        return ProjectPayment::query()->with('invoice')->where('project_id', $project->id)->whereNull('reversed_at')->find($paymentId)
            ?? throw ValidationException::withMessages(['payment' => __('This payment was changed since. Reload the page.')]);
    }

    /**
     * A day typed as "2026-10-22", not after today where the Project is.
     *
     * @throws ValidationException
     */
    private function pastDay(Project $project, ?string $typed, string $field, string $missing, string $future): CarbonImmutable
    {
        $day = RoughTimeframe::typed($typed, $field, $missing);

        if ($day->greaterThan(RoughTimeframe::today($project->location))) {
            throw ValidationException::withMessages([$field => $future]);
        }

        return $day;
    }

    /**
     * @throws ValidationException
     */
    private function reason(?string $reason, string $field, string $missing, int $length): string
    {
        $reason = trim((string) $reason);

        if ($reason === '' || mb_strlen($reason) > $length) {
            throw ValidationException::withMessages([$field => $reason === '' ? $missing : __('Keep this to :max characters or fewer.', ['max' => $length])]);
        }

        return $reason;
    }

    /**
     * Read the Project as it is now, held so two changes cannot cross, and
     * check the person may do this.
     *
     * @template TResult
     *
     * @param  Closure(Project): TResult  $apply
     * @return TResult
     */
    private function held(Project $project, User $actor, string $ability, Closure $apply): mixed
    {
        return DB::transaction(function () use ($project, $actor, $ability, $apply): mixed {
            $project = Project::query()->with('location')->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize($ability, $project);

            return $apply($project);
        });
    }
}

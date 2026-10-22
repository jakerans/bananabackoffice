# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-21 · **Branch:** `main`

**State:** slice 16 is complete and merged (decision 161). **Slice 17,
customer payments, is proposed and waits for Jacob's approval.** Nothing of
it is built.

## The proposal

Plan: [../slice-17-customer-payments-plan.md](../slice-17-customer-payments-plan.md).

- **17a:** the deposit and final invoices on each Project, as sent from
  QuickBooks; payments received against them; the balance; the payment
  state (Deposit due, Deposit paid, Balance due, Paid in full). Cash and
  check need a receipt photo and Finance's approval.
- **17b:** the deposit policy per Location (50% by default), a smaller one
  only by a manager with a reason, and the deposit-paid call within 24 hours.
- **17c:** the final balance due in 7 days, the Production Manager's list of
  whom to chase, Collections at 30 days, and Finance > Receivables by age.

Nothing is sent to customers, no payment is taken in Backoffice, and
QuickBooks is not connected.

## Decisions needed

The plan's eight questions, each with a recommendation. "Follow your
recommendations" is a complete answer. To start, approve the plan or step
17a alone.

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.

# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-22 · **Branch:** `checkpoint-slice-17a-payments`

**State:** step 17a, invoices and payments, is built and waits for Jacob's
review (decision 162). Not merged. What was built is in
[../slice-17-customer-payments-plan.md](../slice-17-customer-payments-plan.md),
"Step 17a as built".

## Browser review (done locally by the agent)

On a job's page, the new Payments card:

1. As its Production Manager, "Record the deposit invoice": the amount is
   suggested; without a number it is refused; recorded as R-1042.
2. "Record a payment" by card with its reference: the job reads Deposit
   paid, in the card and in the header badge.
3. "Record the final invoice", then $500 in cash with a receipt photo: it
   waits for Finance's approval and does not count yet.
4. As Finance: the receipt opens; "Approve" asks to check it first, with
   Cancel focused; the balance drops by $500.
5. The job's activity names each invoice and payment.

## Decisions needed

1. **Merge 17a.**
2. **Should the person who recorded a cash or check payment be barred from
   approving it themselves?** Today someone who holds Finance can do both.
   *Recommended:* yes, someone else approves, unless they are the Owner, so
   the receipt is always seen by a second person (decision 133's control).
   Built in 17b if agreed.

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.

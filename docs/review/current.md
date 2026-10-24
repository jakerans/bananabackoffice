# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-24 · **Branch:** `checkpoint-slice-19-job-costing`

**State:** slice 19, job costing and the financial close, is built straight
through its three steps under decision 170 and waits for Jacob's review.
Not merged. What was built is in
[../slice-19-job-costing-plan.md](../slice-19-job-costing-plan.md), "Step
19a as built", "Step 19b as built" and "Step 19c as built".

## Browser review (done locally by the agent)

On a signed-off sample job sold for $1,750 with $3,900.50 of payouts:

1. As its Production Manager, the Job costing section shows the budget: a
   $787.50 target cost for 55%, everything committed, and the projected
   gross margin in red.
2. A $180 materials cost with no purchase order and no receipt: it reads
   "missing its receipt"; the receipt attached later.
3. "Submit job costing": the provisional margin is kept, and the missing
   purchase order is an accuracy strike, shown on the job.
4. A salesperson does not see the section.
5. As Finance: Finance > Job Costing lists the job to reconcile; on the job,
   "Approve the final gross margin"; it says it closes once paid in full.
6. The last $375 paid; "Close financially": the job reads Financially
   closed, in its header too, and the activity says so.

## Decisions needed

1. **Merge slice 19.**
2. **One reading of decision 133**, in "Step 19b as built": only materials
   and equipment rentals take a purchase order, so only they are struck for
   lacking one.

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.

# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-24 · **Branch:** `checkpoint-slice-18c-after-payouts`

**State:** step 18c, what the job brings in after subcontractor payouts, is
built and waits for Jacob's review (decision 168). Not merged. It is the
last step of slice 18. What was built is in
[../slice-18-change-orders-plan.md](../slice-18-change-orders-plan.md),
"Step 18c as built".

## Browser review (done locally by the agent)

On a sample job sold for $1,750 whose payouts total $3,900.50:

1. As its Production Manager, the Change orders section reads "After
   subcontractor payouts: -$2,150.50 (-122.9%)", now in red with "The
   payouts are more than the contract."
2. Typing a +$500 change order shows the figure now and once signed.
3. Typing a $600 payout in the payout dialog shows it with that payout.
4. As the salesperson, the figure is not shown.

Nothing was saved in this review.

## Decisions needed

1. **Merge 18c.** Slice 18 is then complete.

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.

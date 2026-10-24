# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-24 · **Branch:** `checkpoint-slice-18a-change-orders`

**State:** step 18a, customer change orders, is built and waits for Jacob's
review (decision 166). Not merged. What was built is in
[../slice-18-change-orders-plan.md](../slice-18-change-orders-plan.md),
"Step 18a as built".

## Browser review (done locally by the agent)

On a job sold for $2,400 with its $900 deposit paid:

1. As its Production Manager, "New change order": +$400, one day. It is a
   draft; the contract total stays $2,400.
2. Lowering the price is refused for the Production Manager, with a
   message: only a manager can.
3. "The customer signed": refused without the signed copy; with it, the
   contract total is $2,800.
4. The final invoice is suggested at $1,900: $2,800 less the deposit.
5. As a manager, a change order for -$100 with a reason.
6. The job's activity names each change order. It reads well on a phone.

## Decisions needed

1. **Merge 18a.**

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.

# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-24 · **Branch:** `checkpoint-slice-18b-revisions`

**State:** step 18b, subcontractor work order revisions, is built and waits
for Jacob's review (decision 167). Not merged. What was built is in
[../slice-18-change-orders-plan.md](../slice-18-change-orders-plan.md),
"Step 18b as built".

## Browser review (done locally by the agent)

On a job whose Front porch rail is given to a subcontractor:

1. As its Production Manager, "Change" the package's scope: it says the
   work is given to them, refuses without a reason, and saves with one.
2. The work reads "revised since they accepted"; the revision reads
   "waiting for their acceptance". A salesperson sees the same, without the
   payout.
3. "They accepted the revised work": recorded, the revision reads
   "accepted", and it says to send the work order again.

## Decisions needed

1. **Merge 18b.**
2. **One deviation**, in "Step 18b as built": one acceptance by the
   subcontractor covers every revision made before it, rather than each
   revision having its own status.

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.

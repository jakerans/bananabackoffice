# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-24 · **Branch:** `checkpoint-slice-17c-receivables`

**State:** step 17c, the final balance and who chases it, is built and
waits for Jacob's review (decision 164). Not merged. It is the last step of
slice 17. What was built is in
[../slice-17-customer-payments-plan.md](../slice-17-customer-payments-plan.md),
"Step 17c as built".

## Browser review (done locally by the agent)

1. As a Production Manager, Production > Workspace shows "Balances to chase
   today" with a job whose final invoice went out two days ago.
2. On the job: "The balance is due on Oct 29" and "Contact the customer
   about it today". "Record a contact" with a note: it shows on the job and
   the job leaves today's list.
3. As Finance, the Finance menu's Receivables opens: the total owed, the
   job under "Not yet due" with who chases it and when it was last
   contacted, and the other groups empty. It reads well on a phone.

## Decisions needed

1. **Merge 17c.** Slice 17 is then complete.
2. **Two departures from the plan's wording**, both in "Step 17c as built":
   the Receivables groups follow the collection timeline (days 7, 14, 30)
   rather than "1–7, 8–14, 15–30"; and Receivables is Finance's, while
   managers see balances on each job and the Workspace.

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.

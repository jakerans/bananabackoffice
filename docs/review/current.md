# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-14 · **Branch:** `checkpoint-slice-13-plan`

**State:** **a proposed plan for slice 13, giving work to a subcontractor,
waits for Jacob's approval. Nothing in it is built.** Slice 12 was completed
and merged before it (decision 150).

## The plan

[../slice-13-giving-work-plan.md](../slice-13-giving-work-plan.md), from
product-architecture.md 6.6, 7.4 to 7.8 and 10.1. Three steps, each stopping
for review, all inside Backoffice with no portal:

- **13a, payout:** each Work Package's payout in dollars, changed with a
  reason and every amount kept; seen only by those allowed.
- **13b, the award:** the Production Manager gives a package to one Active,
  compliant subcontractor at the job's Location, who picks one of the
  windows offered; how they accepted is recorded; changing or taking back an
  award says why and whose doing it was.
- **13c, the work order:** a page that prints, with the address, access
  notes, scope, dates, crew, approved colors, payout and any insurance
  deduction; "sent" recorded by the Production Manager; approved colors
  needed first.

Left out, for later: the marketplace and portal, electronic signature, the
budget view and gross margin, paying subcontractors, overruns and the
scorecard, utilization, and any message.

## Decisions needed

Each has a recommendation; "follow your recommendations" is a complete
answer.

1. **Who sees payouts:** a new permission for Production Managers, Location
   Managers, Leadership, the Owner and Finance. Never Sales or the Contact
   Center.
2. **Changing a payout:** free until awarded; after that, with a reason, and
   the subcontractor's acceptance recorded again. Every amount kept.
3. **Who can be given the work:** Active, compliant, at the job's Location.
   A crew smaller than needed is a warning, not a refusal.
4. **The order:** the subcontractor picks one of the windows offered to the
   customer, then the Production Manager confirms with the customer as now;
   if already confirmed, the award is for those dates.
5. **Acceptance:** who, how (in person, phone, text, email) and the day,
   recorded by the Production Manager.
6. **Taking back an award:** a reason every time, and whose doing it was;
   only "the subcontractor withdrew" will count against them later.
7. **The insurance deduction:** one company rate, 10% to start, changed in
   Settings with the day it takes effect; Location rates when needed.
8. **The work order:** a printable page; "sent" recorded; approved colors
   needed first.
9. **The job's address on the work order:** yes, once awarded; never before.
10. **No message** to subcontractors or customers.

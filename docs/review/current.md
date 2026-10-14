# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-14 · **Branch:** `checkpoint-slice-12-plan`

**State:** **a proposed plan for slice 12, subcontractors, waits for Jacob's
approval. Nothing in it is built.** Slice 11 was completed and merged before
it (decision 147).

## The plan

[../slice-12-subcontractors-plan.md](../slice-12-subcontractors-plan.md),
from product-architecture.md section 7 and the stakeholder answers on
compliance. Two steps, each stopping for review:

- **12a, the roster and recruiting:** Production > Subcontractors becomes a
  live page. Each subcontractor's details (business, contact, crew size,
  services, home base by town and ZIP), where they stand from Prospect to
  Active, Benched or Offboarded, a VIP mark with a reason, and their history.
- **12b, compliance:** the base checklist (W-9, general liability, workers'
  compensation with waiver of subrogation, the signed agreement, and the New
  Mexico waiver certificate where required), with the day each was received
  and when it expires; "uses Banana's coverage" for insurance; "Pending
  compliance" and "expiring soon" worked out from it; extra requirements per
  Location.

Left out, each for later: the document files themselves (they need the
private storage of decision 33, a paid service to set up, and a backup for
it), a portal for subcontractors to sign in, giving them work, the
scorecard, and the insurance deduction in dollars. No tax ID number is kept.

## Decisions needed

Ten questions, each with a recommendation, are in the plan under "Decisions
needed". "Follow your recommendations" is a complete answer. The ones that
shape it most:

1. A subcontractor can work at one or more Locations.
2. Recruiting and roster status are one list; VIP is a mark; "Pending
   compliance" is worked out, never set.
4. The Production Manager or a manager benches, unbenches and offboards,
   with a reason every time.
5. Someone not yet compliant can still be Active, shown as "Pending
   compliance", until work can be given out.
6. No document files in this slice; the days only.
7. The New Mexico waiver is required only at New Mexico Locations.

Two new permissions, `subcontractors.view` and `subcontractors.manage`, for
Production and managers only.

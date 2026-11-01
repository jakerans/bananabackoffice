# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-11-01 · **Branch:** `main`

**State:** step 30c goes ahead, except first use, which waits (decision
215): the repository stays public, and no real Locations, staff or customers
go in until the app has been walked through and polished. The plan:
[../slice-30-production-readiness-plan.md](../slice-30-production-readiness-plan.md),
"Step 30c: the plan".

## Backups

**Staging's are dropped (decision 216):** paused, not deleted, with the drill
moved to production. **Production's run hourly and keep 720.** Forge's form
labels that number "days", though 30 kept about 31 hourly backups: the count
is being watched, to confirm 720 gives 30 days.

## Done, and what follows

30c-2, the fixes in the code, is merged and on staging. 30c-3, the written
procedure, is done: [../recovery-procedure.md](../recovery-procedure.md).

**Waiting for Jacob: 30c-4,** the drill on production, from the private guide
page, starting with Deploy. It stops at its step 10 for the agent's reply,
before anything changes the database.

## Next, once 30c-4 is done

The example Location: fill staging's Test Location so the whole job can be
walked through, then Jacob's walk-through and list of notes.

## Also waiting for Jacob

- PaintScout's eight test contacts ("Backoffice Test 01" to "08", at ABQ)
  can only be deleted, which cannot be undone, so they are his to delete.
- The sending domain for customer email, when he chooses (guide in
  [../slice-36-agreement-copy-plan.md](../slice-36-agreement-copy-plan.md),
  "What Jacob sets up").
- Whether the presentation lists each room's work in the customer wording
  the rates already carry ("Bedroom: Paint Walls, Paint Doors").
- A side menu for Settings, hiding pages not built yet, and where the
  insurance deduction moves (decision 202, point 3).
- QuickBooks's live keys, after Intuit's review.
- Slice 32, Reports, waits for his answers.
- The app's top bar is wider than a phone screen on every page.
- On staging: the three browser steps for slice 36 (sign an agreement on a
  sample lead, **Email a copy**, the Audit Log entry).

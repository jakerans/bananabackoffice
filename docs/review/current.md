# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-31 · **Branch:** `main`

**State:** step 30c goes ahead, except first use, which waits (decision
215): the repository stays public, and no real Locations, staff or customers
go in until the app has been walked through and polished. The plan:
[../slice-30-production-readiness-plan.md](../slice-30-production-readiness-plan.md),
"Step 30c: the plan".

## Waiting for Jacob: two fixes in the storage settings

**Staging's backups have failed since 2026-10-28,** most likely because
staging and production share one storage provider in Forge and its key now
reaches only production's bucket. Keys are his to handle:

1. In DigitalOcean, Spaces Object Storage, Access Keys: create a Limited
   Access key for the staging backup bucket only, Read/Write/Delete, and save
   it in the password manager.
2. In Forge, organization settings, Storage providers: add a DigitalOcean
   Spaces provider with that key, named for staging.
3. On the staging server's database backups, switch the configuration to the
   new provider. Press "Back up now" and say when it is done.

**Production's backups work, but keep about 31 hours, not 30 days.** On the
production server's backup configuration, change the retention from 30 to
720. If Forge refuses a number that high, say what it allows.

## In progress

30c-2, the fixes in the code, on `checkpoint-30c`. Then 30c-3, the recovery
procedure and drill on staging, once its backups work; then 30c-4 on
production, from a guide.

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

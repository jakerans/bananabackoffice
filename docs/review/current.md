# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-11-10 · **Branch:** `main`

**State:** production is deployed (2026-11-03) and first use goes ahead now;
the restore drill waits until later (decision 217). The plan:
[../slice-30-production-readiness-plan.md](../slice-30-production-readiness-plan.md),
"Step 30c: the plan", 30c-5.

## Backups

**Staging's are dropped (decision 216):** paused, not deleted. **Production's
run hourly and keep 720;** 81 were kept on 2026-11-02, all successful, so the
setting holds more than the old 31. Putting one back is written down
([../recovery-procedure.md](../recovery-procedure.md)) but not yet proved.

## Next

**First use (30c-5):** the Owner, the Locations, prices and agreement wording
imported, tax rates, pay plans and service areas, then staff invited. The
production check lists each one still missing.

**Step 32a is built (decision 219):** Sales > Reports > Appointments. To look
at it on staging, signed in as Owner, Leadership or a Location Manager:

1. Sales > Reports. Today is shown first; choose **Last month** under Days
   to see the sample appointments.
2. Click **Sold** (or any count): the appointments behind it are listed
   below, with the customer, the work and the result.
3. Click a number in the table by salesperson for that person's list.
4. **Download** beside a table gives a spreadsheet of its counts.

**Step 37a is deployed (decision 220).** Jacob's Owner exists and can sign
in; two-factor is not required on production (decision 221). Still to do:
Settings > Pricing Settings > **Import from PaintScout**: upload the saved
ABQ settings file with both boxes ticked.

**Jacob wants the buildout finished before first use (2026-11-10).**
Proposed, waiting for his yes: build these straight through, each tested,
checked on GitHub and merged to staging, then stop:

1. 37b, the Location checklist (approved, decision 220).
2. 32b, the other Sales figures over a range (Demo, Close and Cancellation
   Rate, Close-on-the-Spot, Pending and Rehash, Lost Reasons, revenue by rep).
3. 32c, Marketing > Reports; 32d, Finance > Reports
   ([../slice-32-reports-plan.md](../slice-32-reports-plan.md)).
4. Settings' side menu, hiding pages not built yet (decision 202, point 3).

Each later area needs its own plan and something from Jacob first: the
Inbox (Messenger and text accounts, and what the AI may answer), Campaigns
and the ad platform connections (built last, decision 183), Color Studio,
and what Production and Company Reports hold.

The PaintScout file can wait until then: ABQ's own settings, saved from
Jacob's PaintScout with his OK for the download.

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

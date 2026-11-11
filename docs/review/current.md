# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-11-11 · **Branch:** `main`

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

**The buildout run is done (decision 222),** each step tested, checked on
GitHub and merged to staging:

- **37b, setting up a Location:** Settings > Locations > **Set up**. A new
  Location starts switched off and is switched on from its checklist.
- **32b, Sales > Reports > Sales figures;** **32c, Marketing > Reports;**
  **32d, Finance > Reports.** Slice 32 is complete.
- **Settings' side menu,** only the pages built.

To look at them on staging, signed in as the Owner:

1. Settings: the menu is on the left; Company, Service Areas, Services and
   Products and Templates are gone.
2. Company > Locations > **Add location**, give a name: it opens on its
   checklist, marked Being set up. Add a ZIP code in step 2; **Switch on**
   stays off until a manager and a salesperson have access there.
3. Sales > Reports > **Sales figures**, Days: Last 12 months: the totals,
   by salesperson, month by month, sold revenue by month, lost reasons.
4. Marketing > Reports: by Lead Source, then **Marketing Source**; speed to
   lead at the bottom.
5. Finance > Reports: profit and loss by Location (empty until QuickBooks is
   connected), AR aging, cash collected, job costing, pay owed.

**Then first use (30c-5),** on production: ABQ's PaintScout file (saved from
Jacob's PaintScout with his OK for the download, then uploaded in Settings >
Pricing Settings), Albuquerque through its checklist, people invited.

**Each later area needs its own plan and something from Jacob first:** the
Inbox (Messenger and text accounts, and what the AI may answer), Campaigns
and the ad platform connections (built last, decision 183), Color Studio,
and what Production and Company Reports hold.

## Also waiting for Jacob

- PaintScout's eight test contacts ("Backoffice Test 01" to "08", at ABQ)
  can only be deleted, which cannot be undone, so they are his to delete.
- Whether the presentation lists each room's work in the customer wording
  the rates already carry ("Bedroom: Paint Walls, Paint Doors").
- Where the insurance deduction moves (decision 202, point 3).
- Connecting each Location's QuickBooks company in Settings > Integrations,
  once the Location exists (live keys are set up, decision 223).
- The app's top bar is wider than a phone screen on every page.
- On staging: the three browser steps for slice 36 (sign an agreement on a
  sample lead, **Email a copy**, the Audit Log entry).

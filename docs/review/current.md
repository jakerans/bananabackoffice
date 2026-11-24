# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-11-23 · **Branch:** `main`

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

**Decision 224, on staging and production (decision 225):** Settings > **Subcontractor
Insurance** holds the insurance deduction, off Company > Locations; a new
Location's time zone is chosen when it is added; Integrations lists a
Location being set up; "Company > Locations" wording; the top bar fits a
phone.

**Decision 226, on staging and production (decision 227):** a Location's
checklist invites its people (or gives someone already in Backoffice a role
there), sets its salespeople's first pay plans, its scorecard owner and
targets, and connects its QuickBooks company, without leaving the page.

**Slice 38, estimates, tax and payment through QuickBooks, is approved
(decision 230).** 38a's test on Intuit's test company is done; its last check, the tax
worked out in Albuquerque's real QuickBooks (decision 231), waits for
Jacob to connect Albuquerque's QuickBooks company on production
([../slice-38-quickbooks-tax-plan.md](../slice-38-quickbooks-tax-plan.md),
"38a results"). Until it is built, Four Corners' towns go in the
estimate's tax list, from the state's July 2026 rate schedule.

**Then first use (30c-5),** on production: ABQ's PaintScout file (saved from
Jacob's PaintScout with his OK for the download, then uploaded in Settings >
Pricing Settings), Albuquerque through its checklist, people invited.

**Each later area needs its own plan and something from Jacob first:** the
Inbox (Messenger and text accounts, and what the AI may answer), Campaigns
and the ad platform connections (built last, decision 183), Color Studio,
and what Production and Company Reports hold.

**Slice 38b, an estimate's tax from QuickBooks, is on staging** (not
production): where a Location has QuickBooks connected, the estimate's tax
box shows QuickBooks' rate in place of the town list. Staging's Intuit test
company has no automated sales tax, so it answers "no tax" there; Four
Corners' real company answered 8.1875% (decision 234).

**Slice 38c is on staging too** (not production): the deposit chosen on
the estimate (percent, flat or none, before tax); a sale recorded at its
price before tax; the commission advance only for a deposit of 25% or more;
and at signing the deposit invoice made and emailed by QuickBooks. **For
Jacob to know:** sales with tax were being recorded with the tax in the
sold price; that is corrected from now on, and earlier sales are unchanged.

**Slice 38d is on staging too** (not production), so slice 38 is complete
(decision 236): payments made in QuickBooks are read onto the job every
fifteen minutes; a payment taken another way and recorded in Backoffice goes
to QuickBooks once it counts; **Make the final invoice** on a Project's
Payments has QuickBooks make it, work out all the tax and email it; a lost
lead's QuickBooks estimates are marked rejected. **Not yet run end to end
against a real QuickBooks company:** the first Four Corners estimate after a
production deploy is that test, and needs Jacob's word.

**Slice 39, production from the accepted estimate, is on staging** (not
production; decision 237,
[../slice-39-production-from-estimate-plan.md](../slice-39-production-from-estimate-plan.md)):
a sold job is held out of Production's lists until its deposit is paid or
waived; the first work package starts from the signed estimate; a window's
last day fills in from its working days; the Brand Ambassador package is
carried over; the job's page shows **Where this job is**. To look at it on
staging, as the Owner: open any job (Production > Workspace): the line of
steps is at the top, and **Next** jumps down the page.

**The first walkthrough on production (decision 233)** is sorted in
[../walkthrough-2026-11-23.md](../walkthrough-2026-11-23.md): one bug fixed,
the rest proposed as slices 39 to 45, waiting for Jacob's answers there.

## Also waiting for Jacob

- PaintScout's eight test contacts ("Backoffice Test 01" to "08", at ABQ)
  can only be deleted, which cannot be undone, so they are his to delete.
- Whether the presentation lists each room's work in the customer wording
  the rates already carry ("Bedroom: Paint Walls, Paint Doors").
- Connecting each Location's QuickBooks company in Settings > Integrations,
  once the Location exists (live keys are set up, decision 223).
- On staging: the three browser steps for slice 36 (sign an agreement on a
  sample lead, **Email a copy**, the Audit Log entry).

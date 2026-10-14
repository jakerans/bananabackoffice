# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-14 · **Branch:** `checkpoint-slice-12b-compliance`

**State:** **slice 12, step 12b (subcontractor compliance) is built and
waits for Jacob's review. It is not merged.** It is the last step of slice
12. Step 12a was merged before it (decision 149).

## What changed

Plan: [../slice-12-subcontractors-plan.md](../slice-12-subcontractors-plan.md),
"Step 12b" and "Step 12b as built".

- **A compliance checklist on each subcontractor:** W-9, general liability
  insurance, workers' compensation with waiver of subrogation, the signed
  agreement, and any requirement of their Locations. Each is recorded with
  the day it was received and, where it expires, the day it expires. The two
  insurance items can be "uses Banana's coverage" instead.
- **"Compliant" or "Pending compliance"** is worked out from it, never set.
  An item expiring within 30 days is marked "Expires soon". It shows on the
  subcontractor's page and on the roster.
- **Renewing** is recording it again; what was recorded before is kept.
- **Settings > Locations > Subcontractor requirements:** each Location's own
  requirements, with a one-click fill-in for the New Mexico waiver
  certificate.
- **Stored:** the requirements (the base four written in) and each record.
  No document and no tax ID number.

## Tests and checks

- `composer test`: formatting, PHPStan and every test; the count is in the
  chat report for this push. 20 are new, in
  `tests/Feature/Subcontractors/ComplianceTest.php`.
- They cover: the base checklist; compliant once everything is in; expiring
  soon at 30 days, expired the day after, renewed; a Location's own
  requirement applying only there, and taken away; every refusal when
  recording; who may record; who may change a Location's requirements; the
  page and the roster.
- Both GitHub jobs, including the migration on MySQL 8.4: see the chat
  report.

## Browser review (done by the agent, on the local site, fictitious data)

1. As the Owner, in Settings > Locations, Northside was given the New
   Mexico waiver certificate with the fill-in button.
2. As the sample Production Manager, the sample subcontractor showed
   "Pending compliance" and all five items missing.
3. Insurance without an expiry day was refused. Then everything was
   recorded: workers' compensation as Banana's coverage, general liability
   expiring on Nov 5 (so "Expires soon"). The page then said "Compliant",
   and the history listed each item.
4. The roster showed "Expires soon" beside them.
5. At a phone's width the card fits.

## Architectural decisions made

- **Compliance is worked out by one class** from the requirements and the
  latest record of each, as readiness is for Projects.
- **Requirements live in a table,** the base four marked as such, so a
  Location's own sit beside them and nothing is hard-coded per state.

## Deviations from the approved plan

None. Three readings, in "Step 12b as built", for Jacob to change if wrong:

- No Location has the New Mexico waiver until someone adds it in Settings.
- "Pending compliance" shows only for subcontractors on the roster.
- A correction is a new record; the wrong one stays in the history.

## Known limitations

- Compliance is somebody's word that the item was received, until the
  documents are stored.
- No work can be given to a subcontractor yet.

## Plan check after slice 12

Against [../product-architecture.md](../product-architecture.md), section 7.
Slice 12 built the roster, recruiting and compliance (7.1 to 7.3) and VIP as
a mark (7.11). Still not built from section 7: the documents, the portal,
giving work (7.4 to 7.9), the scorecard (7.10) and VIP eligibility figures.

**What the plan says is next: giving work to a subcontractor** (6.6, 7.4 to
7.8). A first slice could stay inside Backoffice, with no portal yet:
payout on a Work Package, a direct award to one compliant subcontractor
(the plan's "direct to one subcontractor" release), the crew-selected
window, and the work order, with approved colors required before it goes
out (decision 141). The marketplace, where subcontractors accept and sign
for themselves, needs the portal and comes after.

Things to weigh before that: payout is money owed by Banana, which touches
margin (10.1) and pre-award privacy (7.5); and an award is normally signed
by the subcontractor electronically, which needs the portal.

The other candidates:

- **Document storage** for compliance files and project photos: needs you
  to set up the private DigitalOcean Space of decision 33 (a paid service)
  and its backup first.
- **Average Job Size and Close-on-the-Spot Rate** (5.2): small, any time.
- **The deposit and payments** (10.4, 10.6, 22): need the QuickBooks check.

## Decisions needed

1. **Merge step 12b into `main`?** Slice 12 is then complete.
2. **What next:** a plan for giving work to subcontractors (recommended),
   document storage, the two Sales figures, or something else?

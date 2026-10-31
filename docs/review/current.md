# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-30 · **Branch:** `main`

**State:** slice 36, the customer's copy of the agreement, is complete and
on staging (decision 214). This is the plan check after it, against
[../product-architecture.md](../product-architecture.md). Nothing is being
built until Jacob chooses the next step.

## Plan check after slice 36

**The plan is up to date:** 5.3 records slice 36; no note is out of date.
What 36a added beyond the plan (the 5-minute limit on emailing again, the
production-only setting for customer addresses) is in decision 214.

**The selling flow no longer needs PaintScout.** Pricing, the presentation,
the agreement and the customer's copy are all in Backoffice. What 5.3 still
leaves unbuilt needs a provider or the customer portal: confirmation texts
and videos, Google Calendar, signing by a link (13), and taking the deposit
at signing (22).

**What stops real use is production, not features.** Slice 30's plan says
real work is possible only on production. 30a and 30b are done; 30c,
recovery and first use, is approved (decision 193) and waits for Jacob.

**Still "Coming soon" across Backoffice (unchanged since slice 34):**
- Reports in every Center (slice 32, waiting for Jacob's answers).
- Marketing: Inbox and Campaigns, which need texting and email providers.
- Finance: Payments and Payouts, which need a payment provider.
- Color Studio: Sample Requests, Estimate Requests, Color Catalog (14; the
  Color Plan it describes needs an AI provider).
- Settings: Company, Service Areas, Services and Products, Templates
  (waiting for the Settings side menu decision, 202 point 3).
- Not started: the customer portal (13), Painter for a Day (8), Referrals
  (15), the subcontractor marketplace and portal (7.4, 7.5).

## Proposed next: step 30c, recovery and first use

Already planned and approved (slice 30, decision 193); nothing new is
built.

| Part | What happens | Who |
| --- | --- | --- |
| 1 | **A written recovery procedure,** then the restore drill on staging: staging is put back from its own backup, and the uploaded files from theirs, and checked. Staging's fictitious data comes back from the backup taken just before. | I write it and run it, with Jacob's go-ahead for each Forge action |
| 2 | **The checks left from 30b:** the hourly backups and their first copy, the nightly copy of files, the other storage keys. | I check, read-only |
| 3 | **First use:** the first Owner created on production from the command line, the three Locations added. | Jacob |
| 4 | **When Jacob is ready:** each Location's QuickBooks company (after Intuit's review), and the customer email domain from slice 36's guide, so signed agreements reach customers. | Jacob |

**Questions for Jacob, with recommendations:**

1. **Start 30c now?** *Recommended:* yes, parts 1 and 2 first; they need
   only his go-ahead. Parts 3 and 4 follow at his pace.
2. **The repository back to private (decision 140)?** *Recommended:* decide
   before first use, since real Locations' settings go on production then
   (none go in the repository either way).

**Alternatives:**
- Slice 32, Reports, once its questions are answered.
- A small step for the Settings side menu (decision 202 point 3) and the
  phone top bar.
- The first step of the customer portal (13): customers sign in with a
  one-time code by email and see their signed agreement and what happens
  next. It would use the customer email from slice 36.

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

# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-31 · **Branch:** `main`

**State:** the rest of slice 30 is planned, at Jacob's request, and nothing
is being built until he answers the questions below. The plan, with what the
check found, its steps and known limits:
[../slice-30-production-readiness-plan.md](../slice-30-production-readiness-plan.md),
"Step 30c: the plan". Slice 36 is complete; the plan check after it is
recorded in git (d9a9258).

## Needs attention first

**Staging's database backups have failed every day since 2026-10-28.** The
last good one is from 2026-10-27. Production's have not been checked. Forge
emails failures to Jacob, so the reason may be in his inbox.

## The plan in short

| Step | Delivers | Who |
| --- | --- | --- |
| 30c-1 | Backups working again, staging's and production's | Jacob and the agent |
| 30c-2 | What is owed in the code: the file copy's clean-up, the Location's time on the agreement, the production check updated, the dependency change | The agent |
| 30c-3 | The written recovery procedure, proved on staging | The agent, with Jacob's go-ahead |
| 30c-4 | Recovery proved on production while it is empty | Jacob, from a guide |
| 30c-5 | First use: Owner, Locations, prices, wording, customer email, staff | Jacob, from the guide |

QuickBooks follows when Intuit approves and does not hold up first use.

## Questions for Jacob, with recommendations

1. **Production's backups and read-only access.** May the agent read
   production in Forge: its backups, deployments and the production check,
   changing nothing? *Recommended:* yes. Until now it may touch only
   staging, so otherwise each check is Jacob's.
2. **Drill on production too?** The plan said staging only, but that proves
   nothing about production's own backups or change log, and this is the
   one time a restore there costs nothing. *Recommended:* yes (30c-4).
3. **The repository back to private?** It holds the pay, commission and
   margin rules and the open security notes. At October's pace of about 366
   runs of 18 billed minutes, GitHub's checks would cost about $30 a month,
   less when building slows. *Recommended:* private, before real data.
4. **Raise `vite-plus` to a current 1.x?** A large jump from 0.3.0 and a
   dependency change, so it needs approval. *Recommended:* yes, on its own,
   with a full build and test check; if it breaks the build, stop and report.
5. **The Locations.** Their names and time zones, and whether all use ABQ's
   prices or some have their own. Only Jacob can answer. Staff times follow
   one company time zone, set to Eastern: should it stay? *Recommended:*
   the time zone of the Location that goes first.
6. **How first use starts.** *Recommended:* one Location first; new leads
   from a chosen date, typed in as they arrive; jobs already sold finish
   where they are; PaintScout kept until that Location has signed a few
   real agreements; customer email on before the first real signature.

## Also waiting for Jacob

- PaintScout's eight test contacts ("Backoffice Test 01" to "08", at ABQ)
  can only be deleted, which cannot be undone, so they are his to delete.
- The sending domain for customer email (guide in
  [../slice-36-agreement-copy-plan.md](../slice-36-agreement-copy-plan.md),
  "What Jacob sets up"); now part of 30c-5.
- Whether the presentation lists each room's work in the customer wording
  the rates already carry ("Bedroom: Paint Walls, Paint Doors").
- A side menu for Settings, hiding pages not built yet, and where the
  insurance deduction moves (decision 202, point 3).
- QuickBooks's live keys, after Intuit's review.
- Slice 32, Reports, waits for his answers.
- The app's top bar is wider than a phone screen on every page.
- On staging: the three browser steps for slice 36 (sign an agreement on a
  sample lead, **Email a copy**, the Audit Log entry).

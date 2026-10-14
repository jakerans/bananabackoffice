# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-14 · **Branch:** `checkpoint-slice-11a-work-packages`

**State:** **slice 11, step 11a (Work Packages and the dates offered to the
customer) is built and waits for Jacob's review. It is not merged.** Before
it, step 10c was merged (decision 144) and slice 10 is complete.

## What changed

Plan: [../slice-11-schedule-plan.md](../slice-11-schedule-plan.md), "Step
11a" and "Step 11a as built".

- **"Work and schedule"** is a new part of the Project page, shown on a job
  that is ready to schedule.
- **Work Packages:** the first one is the whole job, and starts from what was
  sold (its name) and the handoff (what is included). The Production Manager
  adds how many working days it takes and the smallest crew. A large job can
  be split into more packages.
- **Dates offered to the customer:** for each package, one or more windows,
  each a first and a last day. Each says how many working days it holds and
  points out one shorter than the work needs. Offered dates are not booked.
  Backoffice sends the customer nothing.
- **Production > Workspace:** each ready job says "No dates offered yet" or
  how many windows are offered.
- **In the customer's Activity:** dates offered and dates withdrawn, with the
  package and the days.
- **Stored:** two new tables, `work_packages` and `work_package_windows`. No
  new permission.

## Tests and checks

- `composer test`: formatting, PHPStan and every test; the count is in the
  chat report for this push. 48 are new, in
  `tests/Feature/Projects/WorkPackagesTest.php`.
- They cover: who may write, as a matrix of six people; every refusal of a
  package field and of a window; nothing saved when refused; working days
  Monday to Friday; nothing written on a job that is not ready; a cancelled
  job; packages and windows of another job refused; the page for the
  Production Manager, a reader and a stranger; the Activity; the Workspace.
- Both GitHub jobs, including the migration on MySQL 8.4: see the chat report.

## Browser review (done by the agent, on the local site, fictitious data)

As the sample Production Manager, then the sample salesperson:

1. A ready job showed "No work package yet." The first package came prefilled
   with the proposal's name and the handoff's scope. Saving it without days
   and crew was refused at both fields.
2. A second package, "Front porch rail", was split off.
3. Dates the wrong way round were refused. Two windows were offered for the
   first package, one of them marked "shorter than the 6 this work needs",
   and one day for the second.
4. One window was withdrawn. The Activity listed the offers and the
   withdrawal.
5. The Workspace said "Dates offered: 2 windows" on that job.
6. A job still getting ready had no work part.
7. The salesperson read the packages and dates, with nothing to press.
8. At a phone's width the part did not run off the screen.

To look yourself: open a job under "Ready to schedule" in Production >
Workspace.

## Architectural decisions made

- **One action writes packages and windows** (`ChangeWorkPackages`), with the
  Project held, the permission checked and readiness checked each time.
- **Readiness is checked again on every change,** so a job that stops being
  ready cannot be changed from a page that was open before.

## Deviations from the approved plan

None. Three readings, in "Step 11a as built", for Jacob to change if wrong:

- Withdrawn dates are in the customer's Activity as well as offered ones.
- A window shorter than the work's days is allowed, with a warning.
- Nothing at all is written on a job that is not ready, packages included.

## Known limitations

- Offered dates book nothing; confirming one with the customer is step 11b.
- Working days are Monday to Friday; public holidays are not known.
- No crew, payout or photos on a package yet (decision 144, questions 2
  and 6).

## Decisions needed

1. **Merge step 11a into `main`, and start step 11b** (the customer's
   confirmation, Scheduled, and rescheduling)? Its questions were answered in
   decision 144.

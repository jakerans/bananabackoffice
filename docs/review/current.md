# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-14 · **Branch:** `checkpoint-slice-13b-award`

**State:** **slice 13, step 13b (the award) is built and waits for Jacob's
review. It is not merged.** Step 13a was merged before it (decision 152).

## What changed

Plan: [../slice-13-giving-work-plan.md](../slice-13-giving-work-plan.md),
"Step 13b" and "Step 13b as built".

- **"Give this work to a subcontractor"** on a package with a payout:
  choose from the Active, compliant subcontractors at the job's Location
  (a small or unknown crew is a warning), the dates they take (one of the
  windows offered, or the confirmed dates), and who accepted, how and when.
- **Once given out:** the customer can be confirmed only for the days the
  subcontractor took; their window cannot be withdrawn; the package cannot
  be removed until the work is taken back.
- **A payout changed after the award** needs a reason, and the page asks
  for their acceptance of the new amount; the old acceptance is kept.
- **"Take back"** needs whose doing it was ("the subcontractor withdrew" or
  "the customer or Banana asked") and a reason. Earlier awards are listed.
- **Who has the work** shows on the job for everyone who may see it, on the
  Schedule page and in the Workspace. Only those with `payouts.view` see
  what it pays.
- **Activity:** work given, taken back, and accepted again, by business
  name; never the payout.
- **Stored:** a new table, `work_package_awards`.

## Tests and checks

- `composer test`: formatting, PHPStan and every test; the count is in the
  chat report for this push. 20 are new, in
  `tests/Feature/Projects/AwardTest.php`.
- They cover: giving the work for an offered window and for confirmed
  dates; the customer confirming only the days taken; no payout, given
  twice, no dates offered; benched, pending compliance and another
  Location refused; each missing part of the acceptance; a payout change
  needing a reason and acceptance again; taking back and giving again; an
  award only ever ended once; who may, for six kinds of person; the page,
  the Activity, the Schedule and the Workspace; the salesperson never
  seeing the payout.
- Both GitHub jobs, including the migration on MySQL 8.4: see the chat
  report.

## Browser review (done by the agent, on the local site, fictitious data)

On the sample job scheduled in slice 11, as the sample Production Manager:

1. The dialog offered the one Active, compliant sample subcontractor (crew
   of 4), filled in their contact as who accepted, and said they take the
   confirmed dates, Feb 1 to Feb 8. Saving without "how" was refused.
2. Given out: the package showed "Given to Sandia Brush Co. (review)", the
   days, the acceptance and "at $3,450.50".
3. The Schedule page showed "Crew: Sandia Brush Co. (review)" in both weeks
   of that work, and "Not given to a subcontractor yet" for the other part;
   the Workspace said "1 part not given out yet".
4. As the sample salesperson: who has the work, and no payout anywhere.

One wording was changed after looking: when the dates are already
confirmed, the message no longer asks to confirm them with the customer.

## Architectural decisions made

- **An award row is ended, never edited,** so "who had the work, at what,
  and why it ended" stays readable; the model refuses any other change.

## Deviations from the approved plan

None. Two readings, in "Step 13b as built", for Jacob to change if wrong:

- Rescheduling keeps the award with the same subcontractor.
- A payout change keeps the work with them, marked until they accept again.

## Known limitations

- Acceptance is somebody's word, until subcontractors can sign for
  themselves.
- No work order yet (step 13c).

## Decisions needed

1. **Merge step 13b into `main`, and start step 13c, the work order?** Its
   questions were answered in decision 151.

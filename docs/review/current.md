# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-15 · **Branch:** `checkpoint-slice-16-plan` (on top of
`checkpoint-overnight-slice-14-onward`)

**State:** **slices 14 and 15 were built overnight under decision 155 and
wait for Jacob's review. Nothing is merged; staging is unchanged.** Slice 13
was merged before the night began (decision 154). Every decision made
overnight was a recommendation taken as the answer; each is listed below for
Jacob to confirm or change.

**Also waiting: the slice 16 plan, document storage,**
[../slice-16-document-storage-plan.md](../slice-16-document-storage-plan.md),
proposed and not approved. Jacob set up the Wasabi buckets this morning
(decision 156). Its ten questions each have a recommendation; the one that
needs his word before anything is built is approving the package Laravel
uses to talk to the storage.

## What was built

All on one branch, one commit per step, each pushed and tested.

### Slice 14, the work and its completion

Plan: [../slice-14-work-and-closeout-plan.md](../slice-14-work-and-closeout-plan.md).

- **14a, the work in progress:** "Work started" and "Work finished" on each
  piece of work; the job becomes "In progress", then "Work complete". Work
  past its last day is marked "Running over" on the job, the Schedule and
  the Workspace. "More days" moves the last day with whose cause it was (the
  subcontractor, the weather, the customer, or another reason). The
  Workspace gains "In progress" and "Work complete" lists.
- **14b, the walkthrough and signoff:** a "Completion" part on the job: the
  internal inspection, then the customer walkthrough (by the Production
  Manager or one of the job's subcontractors), a punch list ticked done, and
  the customer's 100%-satisfied signoff, which makes the job "Signed off". A
  customer not fully satisfied is recorded with what is wrong.
- **14c, the scorecard:** "Score the subcontractor" on finished work, with
  the plan's measures from 1 to 5, the yes-or-no items, an overall rating,
  feedback for them and internal notes; their page shows every scorecard and
  a track record (jobs completed, average rating, and whether they meet the
  plan's bar of five jobs and 4.5).

### Slice 15, the two Sales figures left

Plan and as built: [../slice-15-sales-figures-plan.md](../slice-15-sales-figures-plan.md).

- **Average job size** and **Close on the spot** on Sales > Performance,
  with how each is counted.

## Decisions taken overnight, for Jacob to confirm or change

Slice 14 (the plan's ten questions, all as recommended):

1. Three states after Scheduled: In progress, Work complete, Signed off.
2. "Running over" is a mark from the day after the last day, not a message.
3. More days need the cause; only "the subcontractor" will count against
   them.
4. A failing crew is not replaced in this slice; the award can still be
   taken back with whose doing it was.
5. The inspection is recorded as done, without a checklist, until you send
   the PCA checklist you use.
6. The walkthrough is by the Production Manager or a subcontractor of the
   job; whether they meet the bar is shown, not enforced.
7. Punch items in words, with who fixes them; no signoff while one is open.
8. Signoff records who, how, the day and "100% satisfied".
9. The scorecard as the plan lists it; never changed, corrected by a note.
10. No message to anyone.

Slice 15:

11. A "job" for Average job size is a sold proposal.
12. "On the spot" means sold at the first demo, by the end of its day.

Readings made while building, in each plan's "as built" sections: one
inspection and walkthrough per job; one scorecard per piece of work given
out; adding a note to a scorecard is in the code but not yet on a page.

## Tests and checks

- `composer test` passed on slice 14 complete: formatting, PHPStan and
  3,053 tests (3,052 passed, 1 skipped as before). Slice 15's figures and
  pages were tested on their own and the whole suite again before the last
  push; see the chat report.
- New tests: `WorkProgressTest`, `CompletionTest`, `ScorecardTest`,
  `JobSizeAndSpotTest`.
- Both GitHub jobs (SQLite and MySQL 8.4) for each pushed step: see the chat
  report.

## Browser review (done by the agent, on the local site, fictitious data)

The sample job's two pieces of work were given out and their dates moved
into the past, in the local database only, so the whole of slice 14 could be
walked through:

1. Both parts started on Oct 12; the job showed "In progress" and "Running
   over".
2. "More days" refused "another reason" without words, then moved the first
   part to Oct 16 for the weather; the Workspace showed the job under "In
   progress" with "Running over" and its crew.
3. Both parts finished: "Work complete", and the Completion part appeared.
4. Inspection, walkthrough by the sample subcontractor (shown with "1 job
   completed · not scored yet"), a punch item, done, then signoff (refused
   without "how"): "Signed off".
5. Both parts scored (5 and 4): the subcontractor's page said "1 job
   completed · rating 4.5 from 2 scorecards".

## Not done overnight, and why

- **Financial close** (job costing, receipts, the final balance) and
  **deposits and payments**: they need Finance's side and the QuickBooks
  check, which needs your account.
- **Document storage** (compliance files, photos): needs the private
  DigitalOcean storage of decision 33, a paid service you would set up.
- **A sign-in for subcontractors or customers** (the marketplace, the
  portal): the first time anyone outside Banana would sign in; it needs your
  decisions on security first.
- **Brand Ambassador obligations:** the Brand Ambassador packages are not
  built on the Sales side yet.
- **Production > Performance and utilization:** they need figures not yet
  kept, such as crews' available days and job costs.

## Decisions needed

1. **Merge the overnight branch into `main`** (slices 14 and 15), or tell me
   what to change first.
2. **The decisions taken overnight above:** confirm or change any.
3. **What next:** of the blocked items, which you want to unblock first.

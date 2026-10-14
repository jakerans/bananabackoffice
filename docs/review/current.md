# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-14 · **Branch:** `checkpoint-slice-11b-confirmed-schedule`

**State:** **slice 11, step 11b (the confirmed schedule) is built and waits
for Jacob's review. It is not merged.** Step 11a was merged before it
(decision 145).

## What changed

Plan: [../slice-11-schedule-plan.md](../slice-11-schedule-plan.md), "Step
11b" and "Step 11b as built".

- **"The customer confirmed"** on each offered window: who said yes, how and
  on which day. That package then has those dates.
- **The job is "Production: Scheduled"** once every package has confirmed
  dates; until then the part says "Partly scheduled".
- **Rescheduling:** new dates, a reason, and who agreed. The earlier dates
  stay listed with who changed them and why.
- **The salesperson is told in Backoffice** when the job is scheduled and
  when it is rescheduled, with the days only, and the Lead shows the
  confirmed dates in place of the rough timeframe.
- **Production > Workspace** has a third list, "Scheduled", with each job's
  dates.
- **Activity:** dates confirmed and rescheduled, with the work, the days and
  the reason.
- **Stored:** two columns on `work_packages` and a new table,
  `work_package_schedules`, whose rows are never changed or deleted. No new
  permission.

## Tests and checks

- `composer test`: formatting, PHPStan and every test; the count is in the
  chat report for this push. 29 are new, in
  `tests/Feature/Projects/ScheduleTest.php`.
- They cover: confirming and becoming Scheduled; a split job scheduled only
  when both parts are; every refusal of a confirmation and of a reschedule;
  dates that have started; no more offers or removal once confirmed; a new
  package taking the job back to pre-production; a change of Production
  Manager keeping it Scheduled; the history kept and refusing changes; who
  may, as a matrix of six people; the notices (days only, no address); the
  page, the Lead, the Activity and the Workspace.
- Both GitHub jobs, including the migration on MySQL 8.4: see the chat
  report.

## Browser review (done by the agent, on the local site, fictitious data)

On the job given two packages in step 11a's review:

1. Confirmed the first package's dates: refused until "how" was chosen; the
   part then said "Partly scheduled" and the job was still "Pre-production".
2. Confirmed the second: "Production: Scheduled".
3. Rescheduled the first: refused without a reason, then moved; the history
   showed the new and the old dates with the reason. The Activity listed it.
4. The Workspace showed the job under "Scheduled" with its dates, and none
   under "Ready to schedule".
5. As the salesperson: the Lead said "Scheduled: Tue, Jan 12 – Mon, Feb 8,
   2027. Confirmed with the customer."; both notices were in the bell; the
   job offered nothing to press.
6. At a phone's width the part did not run off the screen.

Two things were changed after looking: "Remove" no longer shows on work that
has confirmed dates (it was refused anyway), and a scheduled job in the
Workspace no longer repeats its rough timeframe beside its dates.

## Architectural decisions made

- **Scheduled is worked out from the packages after every change** and
  stored as the production state, so the lists and the badge read it
  directly.
- **The confirmation's "how" uses the same four ways as the colors'
  approval** (`ColorApprovalMethod`), rather than a second list that would
  say the same.

## Deviations from the approved plan

None. Three readings, in "Step 11b as built", for Jacob to change if wrong:

- A reschedule records who agreed to the new dates.
- The salesperson is told only when the whole job is scheduled, not when
  one part of a split job is.
- Confirmed dates cannot be taken away without new ones.

## Known limitations

- Two jobs can be confirmed for the same days; there are no crews yet.
- Production > Schedule and the "needs dates soon" flag are step 11c.

## Decisions needed

1. **Merge step 11b into `main`, and start step 11c** (Production > Schedule
   and "needs dates soon")? Its questions were answered in decision 144.

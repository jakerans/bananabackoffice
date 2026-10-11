# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-10 · **Branch:** `main`

**State:** nothing waits for review. **Slice 6, appointment booking, is
complete and merged into `main`:** steps 6a, 6b and 6c, under decisions 90 to
120. What comes next is not chosen; it needs its own plan and Jacob's approval.

## Step 6c after its merge

Merged on 2026-10-10 (`a3e0f7d`) at Jacob's word ("Merge it good to go").

| Check | Result |
| --- | --- |
| GitHub, merge commit `a3e0f7d` | Both jobs passed (SQLite, and MySQL 8.4) |
| Staging deployment | Finished, on the merge commit |
| Staging database | The five new columns exist; no migration is pending; the waiting period reads 14 days |
| Call-queue timer on staging | Still ticking. It is now also what releases cancelled Leads. |
| Staging data | No Lead is with Sales and no Lead is in either call queue, so nothing was released or sorted by the deployment |

Nobody has opened the staging pages in a browser. Every browser review of
slice 6 was on the local site. The handoffs for 6a, 6b and 6c, with their
browser-review results, are in this file's Git history.

## What slice 6 built

The plan, with what was built in each step, is
[../slice-6-appointments-plan.md](../slice-6-appointments-plan.md). In short:

- **6a:** book an appointment for a Qualified Lead, which issues it to Sales;
  "booked in error"; the bell; Sales > Calendar.
- **6b:** reschedule, move to another salesperson, cancel with a reason, book
  again without a new issued count, send back to Contact Center, and the
  notice that tells the call center a Lead came back from Sales.
- **6c:** release to the call center after 14 days (a setting), the Cancelled
  Appointments queue with its own button, the warning to the salesperson, and
  taking a released Lead back.

## Choices that stand unless Jacob changes them

Made while building and reported at each step:

- **6a.** The salesperson is told even when they booked their own Lead. Sales >
  Calendar shows all of a person's Locations at once. A Lead whose appointment
  time has passed stays "Appointment booked". Opening the bell marks its
  notices seen.
- **6b.** The counting question defaults to "still counts" outside the Send
  back dialog. A Lead that came back and is booked again is a new issued Lead.
  Booking with a different salesperson tells only the new one. "Booked in
  error" is still offered on a passed appointment. "Other" is stored last in
  the reasons list.
- **6c.** The 14 days count from when the Lead became "Appointment cancelled"
  and start again on a take-back. Changing the number applies to Leads already
  waiting. The queue follows the appointment, not the manager's answer. A
  manager sees released Leads but cannot take one back for a salesperson.

## Still open

- **With Sales Leadership:** which salesperson an issued Lead counts for after
  a move or a re-issue; the real cancellation reasons; offer validity; the
  Pending ownership period.
- **Left out of slice 6, each needing its own plan:** the after-action report
  (whether an appointment was held or missed, and its outcome); Pending and
  Rehash (rule 14); Projects created when a sale is Won (rule 13); Google
  Calendar; a calendar grid; any message to a customer.
- **Small follow-ups from earlier work, none authorized:** the Call Queue's
  lists stop at 25 without saying so; a card phone and form phone mismatch; a
  forbidden page when access is lost with the Skip dialog open; one
  occasionally slow race test; the Node formatter test skips on Windows;
  staging runs PHP 8.5 where the project names 8.4.

## Decisions needed from Jacob

1. **What to work on next.** Recommendation: the after-action report, because
   four things already built wait on it (a passed appointment has no outcome,
   a missed one is not recorded, Pending cannot start, and a Project cannot be
   created at Won). It would begin as a plan with questions, like slice 6.
2. **Whether Jacob wants to look at staging in a browser himself.** Nobody has.
   The implementation agent does its browser reviews on the local site and
   does not sign in to staging.

## Tests and checks

This branch changes status lines in documents only. No application code, test
or migration changed. `composer test` was not run for it. The GitHub jobs are
reported with the branch, not recorded here.

## Browser review

None. Nothing on this branch changes a screen.

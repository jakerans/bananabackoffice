# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-11 · **Branch:** `checkpoint-customer-appointments`

**State:** one small job is built and waits for Jacob's word to merge: the
Appointments section on the customer page, the one item the plan check found
simply missed. It sits on top of the documents branch
(`checkpoint-slice-6-merge-record`), so merging this branch brings the plan
check, decisions 121 to 123, the passages added to the plan, and this job
together. `main` and staging have none of it.

**Slice 6, appointment booking, is complete and merged into `main`** (steps 6a,
6b and 6c, decisions 90 to 120). The plan check Jacob asked for on 2026-10-11,
which now follows every slice (decision 122), is below.

## The job on this branch: appointments on the customer page

Plan: [../product-architecture.md](../product-architecture.md), 11, which lists
Appointments among the sections of a customer. Jacob said to make the
adjustments recommended in the plan check on 2026-10-11.

**What changed.** The customer page has a new section under Leads,
"Appointments": every appointment booked for the customer's Leads, latest
first, each with its day and time in its own location's time, the property,
the location, the salesperson, and where it stands: Upcoming, Time has passed,
Cancelled with its reason, or Booked in error. Each row opens its Lead.

**Who sees it.** The same rule as the Leads section above it: an appointment is
listed only when the person may see its Lead. One for a Lead at a location they
cannot work in is not listed and not hinted at, and someone who sees customers
but no Leads is shown no Appointments section at all. No permission was added.

**No database change, no new package.** One relation on the customer, one
query and one section on the page.

**Tests and checks.**

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine | **Passed:** 2,288 tests (2,287 passed, 1 skipped, 11,696 assertions) in 344 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub SQLite and MySQL 8.4 jobs | Reported with the branch; not recorded here. |
| Browser | Done on 2026-10-11 by the implementation agent, on the local site. A fictitious customer with two appointments showed both, latest first: "Wed, Oct 14, 2026 · 3:00 PM to 4:30 PM PDT", its property, Northside, Sam Sales, "Upcoming", and under it the earlier one marked "Cancelled: No longer needed". Clicking a row opened its Lead. The salesperson saw the same two rows. No page errors. |
| Merge, staging | **Not done.** |

New tests, `tests/Feature/Customers/CustomerAppointmentsTest.php` (7): the empty
section; four appointments in four states, latest first, each opening its
Lead; each in its own location's time; an appointment for a Lead the person
cannot see is not listed; an appointment stops being listed when its Lead
moves somewhere the person cannot see; someone who sees customers but not
Leads gets no section; another customer's appointments are not shown.

**Reading made while building.** "Time has passed" is what a standing
appointment shows once its time is over. Backoffice does not yet know whether
it was run or was a no-show (decisions 121 and 123); that comes with the
after-action report.

**Known limitation.** The section is on the customer page. The Activity and
Notes workspace beside it already listed each booking and change as events and
is unchanged.

## Step 6c after its merge

Merged on 2026-10-10 (`a3e0f7d`) at Jacob's word. Both GitHub jobs passed on
the merge (SQLite, and MySQL 8.4); staging deployed it; the five new columns
exist on staging, no migration is pending, the waiting period reads 14 days and
the call-queue timer was still ticking. Staging has no Lead with Sales and none
in either call queue. Nobody has opened the staging pages in a browser; every
browser review of slice 6 was on the local site.

## New decisions recorded on this branch

| Decision | What |
| --- | --- |
| 121 | An appointment's outcome (he named no-show, cancellation, reschedule) and a proposal's outcome (he named Pending, Lost) are two different things, recorded separately. One proposal can have several appointments and one appointment can produce several proposals. |
| 122 | After every slice the work is checked against [../product-architecture.md](../product-architecture.md), and the next step is proposed from it. |
| 123 | An appointment that ran must have a result (Sold, Pending or Lost); "it took place" is not one. Whether a proposal was presented is recorded as its own fact, and Demo Rate is counted from it. |

## The plan check after slice 6

The plan is [../product-architecture.md](../product-architecture.md), all 24
sections. It was read in full for this check.

### Where the work stands against the whole plan

| Plan section | State |
| --- | --- |
| 3. The five Centers | Built: the navigation shell. Most items inside each Center are "Coming soon". |
| 4.2 Marketing: Contact Center | Partly built. See the next table. |
| 4.5, 11. Customers and the customer workspace | Partly built: the customer list and page, contacts, properties, Notes, Activity, the customer's Leads. Not built: conversations, saved segments, broadcasts, and the Appointments, Projects, Payments and Documents sections. |
| 5.1, 5.4 Sales: ownership and Calendar | Partly built by slice 6. See the next table. |
| 12. The booking engine | A simple version is built: a person picks the salesperson and the time. See the next table. |
| 18. Company: Locations and Settings | Partly built: Locations with service areas and timezones, the managed lists, Contact Center settings. |
| 9.5 People and access | Built: users, roles, location access, invitations, deactivation. |
| 20. Ownership transitions | The first one is built: Contact Center to Sales at booking, and back. |
| 21. Attribution | Built: Marketing Source, Lead Source and Lead Channel on every Lead. Not built: lifetime value, and anything for self-booking. |
| 4.1, 4.3, 4.4, 4.6 Marketing performance, Inbox, Campaigns, Reports | Not started. |
| 5.2, 5.3, 5.5 to 5.9 Sales performance, Workspace, Pending and Rehash, after-action report, commission, Leaderboard, Reports | Not started. |
| 6 to 10. Production, subcontractors, Painter for a Day, Finance, project economics | Not started. |
| 13 to 17, 19, 22, 23. Customer portal, Color Studio, referrals, warranty, performance boards, AI, payments, workforce | Not started. |

Nothing in the "not started" rows was skipped: each is later in the plan.

### Inside the areas already built: what the plan describes and is not there

These are the places where the plan says more than was built. Each was left out
on purpose at the time and written down as left out, but they have never been
put in one list. None is scheduled.

| # | The plan says | What exists | Where it was set aside |
| --- | --- | --- | --- |
| 1 | **Booking engine (12).** One engine offers times from the salespeople's calendars, checks travel between appointments, chooses the salesperson by eligibility, performance and fairness, and is used by the call center, the customer's self-booking, rescheduling and rehash. Sales Slot Utilization is measured from it. | A person chooses the salesperson and types the day and time. Backoffice refuses only a double booking. No availability is shown, nobody is chosen automatically, no slots, no utilization figure. | Decision 93: real availability is not in Backoffice until Google Calendar is. |
| 2 | **Confirming an appointment (5.1, 5.4).** The salesperson owns confirmation. | Nothing. An appointment is booked or it is not. | The confirmation process is open with Sales Leadership (plan, 24). |
| 3 | **Google Calendar (5.4)** as the source of conflicts. | Nothing. | Slice 6 plan, "Not in slice 6". |
| 4 | **New Lead handling (4.2).** A new Lead pops up for a setter who is not dialling, with Call or Snooze; snoozed Leads sit in a tray with the timer running; a setter who is dialling gets it as the next call. | New Leads are first in line when a setter presses Next lead. Nothing pops up and nothing can be snoozed. | Slice 4's boundary in the roadmap: "no ... popup, snooze tray". |
| 5 | **Aged Leads (4.2).** After about 12 attempts phone work moves to an Aged Leads queue that management switches on. | After the last attempt a Lead goes on the manager's "Cadence finished, no contact" list, with a "Put back in queue" button. There is no Aged Leads queue. | Slice 4's boundary in the roadmap: no Aged Leads. |
| 6 | **Short-call report and Connection Rate (4.2).** A daily manager report of suspiciously short calls, and Connected Leads over Leads Received. | Every attempt stores its length and whether it connected. Nothing reports on them. | Slice 3 stored the evidence only. |
| 7 | **Call recordings and transcripts (4.2).** | No phone provider is connected. Attempts are typed in by hand. | Provider choice is open (roadmap, slice 3). |
| 8 | **Appointments on the customer page (11).** | **Fixed on this branch (2026-10-11):** the customer page now has an Appointments section. Before, only the customer's Activity listed bookings and changes. | Not written down anywhere. **This one was simply missed.** |
| 9 | **A no-show (decision 121; plan 5.6).** | A cancellation and a reschedule are recorded on the appointment. A no-show, and whether the visit took place, are not. | Decision 84: left for the after-action report. |
| 10 | **Rehash (5.4, 5.5, 12).** | Nothing. | Rule 14 and slice 6 plan: needs Pending, which needs the after-action report. |

### Built, and not in the plan

Slice 6 built rules that Jacob decided in the slice 5 rulebook and in decisions
90 to 120, and that the plan itself does not mention:

- "Booked in error", and whether a Lead that leaves Sales still counts as
  issued.
- A manager sending a Lead back to the Contact Center.
- The 14-day wait after a cancellation, the release, and the warning.
- The Cancelled Appointments call queue with its own button.
- A salesperson taking a released Lead back.

They are decided and recorded, in [../decisions.md](../decisions.md) and the
rulebook. The plan did not say so. **On 2026-10-11 Jacob said to make the
adjustments, and the plan now has a short dated passage in each of sections
4.2, 5.1, 5.4 and 5.6** that states them, says what is built and what is not,
and points at the rulebook. Nothing already in the plan was removed or
reworded.

### Where the plan and Jacob's new distinction disagree

Section 5.6 puts "Won / Pending / Lost" on the after-action report, which is
filled in for one appointment. Decision 121 says those belong to a proposal,
and that appointments and proposals are many to many. Decision 123 adds that an
appointment that ran must have a result even when no proposal was presented,
and that this no-demo case is what Demo Rate is counted from. Section 5.6 now
carries a dated passage saying so. **Two things are left for the after-action
report's build plan,** and are written at the end of decision 123: where the
result is kept when there is no proposal yet, and what an appointment shows
when its proposals have different results. **There is no proposal record in
Backoffice yet.**

### Which part of the plan the next step comes from

The plan's own order after "Lead Issued" (section 20) is: confirmation, the
appointment, then Pending, Rehash, Won or Lost. The after-action report (5.6)
is where the appointment's result and each proposal's result are first
written down, and Pending (5.5), Projects at Won (rulebook rule 13), commission
(5.7) and the Sales performance figures (5.2) all read from it.

## Decisions needed from Jacob

1. **Merge this branch,** or say what to change. It brings the documents and
   the customer-page job together.
2. **The other nine items in the table.** Recommendation: leave them where
   they are and take each up when its part of the plan comes round; say so if
   any should come sooner. Item 1, the booking engine, is the largest gap
   between the plan and what the call center has today.
3. **The next slice.** Recommendation: the after-action report (plan 5.6,
   decisions 121 and 123), starting with a build plan. It has to begin with a
   simple proposal record, because none exists.

Answered on 2026-10-11 and no longer open: the plan is updated with what slice
6 decided; an appointment that ran needs a result and "it took place" is not
one; whether a proposal was presented is its own fact (decision 123).

## Choices from slice 6 that stand unless Jacob changes them

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

## Still open elsewhere

- **With Sales Leadership (plan, 24):** the confirmation process; the objection
  list; the Lost Reason list; the Pending ownership period; proposal validity;
  which salesperson an issued Lead counts for after a move or a re-issue; the
  real cancellation reasons.
- **Small follow-ups from earlier work, none authorized:** the Call Queue's
  lists stop at 25 without saying so; a card phone and form phone mismatch; a
  forbidden page when access is lost with the Skip dialog open; one
  occasionally slow race test; the Node formatter test skips on Windows;
  staging runs PHP 8.5 where the project names 8.4.

## Tests and checks

For the job on this branch, see its own section at the top. The plan check and
the decisions are documents only.

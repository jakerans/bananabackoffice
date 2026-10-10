# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-10 · **Branch:** `checkpoint-slice-6b-plan`

**State:** a plan waiting for decisions. Slice 6, step 6b is planned in detail
and **nothing of it is built or approved.** It needs Jacob's answers to nine
questions, below: five left from the first version of the plan, and four
raised by his answers to its readings. This branch holds documents only.

Step 6a is built, reviewed in the browser and **merged into `main` on
2026-10-10** (`48adc42`) at Jacob's word. After that merge both GitHub jobs
passed, staging deployed it, the new tables exist on staging with no migration
pending, and the call-queue timer was still ticking. Nobody has opened the
staging pages in a browser. The 6a handoff, with its browser-review results, is
in this file's Git history.

## Step

Slice 6, step 6b: change, cancel and send back an appointment. Plan:
[../slice-6-appointments-plan.md](../slice-6-appointments-plan.md), "Step 6b".
Rules: [../slice-5-appointment-rulebook.md](../slice-5-appointment-rulebook.md),
rules 6, 7, 8 (first two points), 9 and 11.

Objective: once an appointment is booked, staff can change its time, a manager
can give it to another salesperson, a cancellation is recorded and the Lead can
be booked again, and a manager can send the Lead back to the call center.

## What Jacob has decided since 6a

Written into [../decisions.md](../decisions.md) on this branch:

| Decision | What |
| --- | --- |
| 97, 103 | A Lead that comes back to Contact Center shows Qualified and is assigned to nobody; the call queue hands it out. |
| 98 | Cancelled Appointments gets its own button on the Call Queue and is worked as hot Leads, beside new Leads. Built in step 6c. His message said "canceled deployments"; it is read as cancelled appointments. |
| 99 | After a cancellation the Lead can be rebooked with the same salesperson, and that adds nothing to Leads Issued, so a salesperson's NSLI is not hurt by getting a cancelled booking back on the calendar. |
| 100 | Whether a Lead that leaves Sales still counts as issued depends on the reason. One that should never have been booked does not count. |
| 101 | A Lead whose appointment was cancelled can still be moved to another Location, returned to central or disqualified (his example: the wrong service area). |
| 102 | A passed appointment is not changed, on the condition that a missed appointment has a clear way to be booked again. |
| 104 | A cancellation by the salesperson or the company is recorded the same way as the customer's, under the same rules. "Who cancelled" offers all three. |

Still with Jacob, in his words "I want to think about that": whether sending a
Lead back should always cancel an upcoming appointment (question 20).

## The proposal, in short

- **Reschedule:** the salesperson or a manager changes the time. Same
  salesperson, same issue, old times kept and shown.
- **Move to another salesperson:** a manager only. Both people are in the
  history; the new one is told by bell and email, the earlier one by bell.
- **Cancel:** records who cancelled, a reason from a new Cancellation Reasons
  list on Lead & Sales Lists, and an optional note. The Lead stays with the
  salesperson and still counts as issued. It can be booked again.
- **Send back to Contact Center:** a manager only, with a reason and a note.
  The reason decides whether the Lead still counts as issued. The Lead shows
  Qualified and re-enters the call queue.
- **A cancelled Lead** can also be moved to another Location, returned to
  central or disqualified.
- **After an appointment's time has passed** it cannot be changed; the card
  offers "Book another appointment".
- **Sales > Calendar** gains a second list, "To rebook".
- **No new permission, no new package, nothing sent to a customer.** Two new
  tables (the history of changes, and the reasons list), one new appointment
  state and, if agreed, one new Lead status.
- **Not in 6b:** the 30-day release, the Cancelled Appointments button, and a
  salesperson picking a released Lead back up. Those are 6c.

## Decisions needed from Jacob

Each has a recommendation, so "agreed to all" is an answer. They are questions
13 and 15 to 22 in the plan, which gives the alternatives. Questions 12 and 14
are answered (decisions 103 and 104).

| # | Question | Recommended |
| --- | --- | --- |
| 13 | Where does a Lead a manager sends back restart in the call cadence? | It starts over at attempt 1, due at the next opening of calling hours, in the ordinary queue with the follow-up calls. The alternative is to treat it as hot, with new Leads. |
| 15 | What does a Lead show after its appointment is cancelled, while still with the salesperson? | A new status, "Appointment cancelled", so it is plain which Leads need rebooking. |
| 16 | Who may record a cancellation? | Anyone who may book that Lead: a manager, a Call Center Agent, and the salesperson whose appointment it is. |
| 17 | What does the Cancellation Reasons list start with? The real reasons are still open with Sales Leadership. | One entry, "Other (see note)", so it works on its first day; Owner and Leadership add the real ones. Or Jacob gives a starting list. |
| 18 | Rebooking with a different salesperson while the Lead is still with the first one. | A manager only, recorded as a move. An agent or the salesperson can rebook with the same salesperson only. |
| 19 | How does a person say whether a Lead leaving Sales still counts as issued? | Send back (and disqualifying a Lead that is with Sales) asks one required question: "It should never have been booked" (no longer counts) or "Another reason" (still counts). Only a manager may give the first answer. |
| 20 | Sending back a Lead whose appointment is still upcoming. | Keep two separate actions. "Move to another salesperson" keeps the appointment, for when it should still happen. "Send back" is for a Lead the call center must work again, so it cancels the appointment, and its dialog points to Move. |
| 21 | What happens to a cancelled Lead that is moved to another Location or returned to central? | It leaves the salesperson and is treated exactly as a Lead sent back, with the same counting question. |
| 22 | The path after a missed appointment. | Once the time has passed the card offers "Book another appointment": same salesperson by anyone who may book, a different one by a manager, no new issued count. Whether it was held or missed is not recorded until the after-action report. |

Tradeoffs worth knowing:

- **13.** Starting over means up to a full cadence of calls to someone who
  already had an appointment. Treating it as hot gets a faster first call but
  puts it ahead of follow-ups that have waited longer.
- **15.** A new status appears in every list and filter of Leads. Without it,
  cancelled Leads look the same as booked ones until the Lead is opened.
- **16.** Letting agents record a cancellation matches who answers the phone,
  and it lets an agent take a Lead off a salesperson's calendar. Every
  cancellation is recorded with who did it.
- **19.** Two answers are quick and cannot be read two ways. They do not say
  why a Lead came back beyond the manager's note; a named list of reasons
  would, and can be added later without losing anything.
- **20.** Keeping the actions apart means a salesperson who cannot make an
  appointment has to tell a manager outside Backoffice. A request button would
  close that gap and is a feature of its own.
- **22.** "Book another appointment" gives the path without Backoffice claiming
  to know whether the first one was held. A "Missed" mark would be more
  informative and starts appointment outcomes early.

## Readings the plan makes

The first version listed four. Jacob answered them: three are decisions 100 to
102 and one is question 20. One small reading is left, built this way unless
Jacob says otherwise: a reschedule or a move to a time already past is refused,
as a booking is.

## Tests and checks

Documents only. No application code, test or migration changed on this branch.
`composer test` was not run for it. The GitHub jobs are reported with the
branch, not recorded here.

## Still open from step 6a

Four choices made while building 6a stand unless Jacob changes them: the
salesperson is told even when they booked their own Lead; Sales > Calendar
shows all of a person's Locations at once; a Lead whose appointment time has
passed stays "Appointment booked" with no button; opening the bell marks its
notices seen.

## Browser review

None. Nothing on this branch changes a screen.

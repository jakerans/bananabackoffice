# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-10 · **Branch:** `checkpoint-slice-6b-plan`

**State:** a plan waiting for decisions. Slice 6, step 6b is planned in detail
and **nothing of it is built or approved.** It needs Jacob's answers to seven
questions, below. This branch holds documents only.

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
| 97 | A Lead that comes back to Contact Center shows Qualified. Who it is assigned to is not settled (question 12). |
| 98 | Cancelled Appointments gets its own button on the Call Queue and is worked as hot Leads, beside new Leads. Built in step 6c. His message said "canceled deployments"; it is read as cancelled appointments. |
| 99 | After a cancellation the Lead can be rebooked with the same salesperson, and that adds nothing to Leads Issued, so a salesperson's NSLI is not hurt by getting a cancelled booking back on the calendar. |

## The proposal, in short

- **Reschedule:** the salesperson or a manager changes the time. Same
  salesperson, same issue, old times kept and shown.
- **Move to another salesperson:** a manager only. Both people are in the
  history; the new one is told by bell and email, the earlier one by bell.
- **Cancel:** records who cancelled, a reason from a new Cancellation Reasons
  list on Lead & Sales Lists, and an optional note. The Lead stays with the
  salesperson and still counts as issued. It can be booked again.
- **Send back to Contact Center:** a manager only, with a note. The Lead shows
  Qualified and re-enters the call queue.
- **Sales > Calendar** gains a second list, "To rebook".
- **No new permission, no new package, nothing sent to a customer.** Two new
  tables (the history of changes, and the reasons list), one new appointment
  state and, if agreed, one new Lead status.
- **Not in 6b:** the 30-day release, the Cancelled Appointments button, and a
  salesperson picking a released Lead back up. Those are 6c.

## Decisions needed from Jacob

Each has a recommendation, so "agreed to all" is an answer. They are questions
12 to 18 in the plan, which gives the alternatives.

| # | Question | Recommended |
| --- | --- | --- |
| 12 | Who has a Lead after it comes back to Contact Center? His answer read "assigned to somebody"; the recommendation had been "nobody". | Nobody: the call queue hands it to the next available person. If it should be a named person, say which: the one who booked it, or one the manager picks. |
| 13 | Where does a Lead a manager sends back restart in the call cadence? | It starts over at attempt 1, due at the next opening of calling hours, in the ordinary queue with the follow-up calls. The alternative is to treat it as hot, with new Leads. |
| 14 | A cancellation that does not come from the customer (the salesperson or the company cancels). | The same record and rules. "Who cancelled" offers customer, salesperson or company. |
| 15 | What does a Lead show after its appointment is cancelled, while still with the salesperson? | A new status, "Appointment cancelled", so it is plain which Leads need rebooking. |
| 16 | Who may record a cancellation? | Anyone who may book that Lead: a manager, a Call Center Agent, and the salesperson whose appointment it is. |
| 17 | What does the Cancellation Reasons list start with? The real reasons are still open with Sales Leadership. | One entry, "Other (see note)", so it works on its first day; Owner and Leadership add the real ones. Or Jacob gives a starting list. |
| 18 | Rebooking with a different salesperson while the Lead is still with the first one. | A manager only, recorded as a move. An agent or the salesperson can rebook with the same salesperson only. |

Tradeoffs worth knowing:

- **12.** "Nobody" keeps one rule for the whole call center: the queue decides
  who calls next. A named person means the Lead waits for that one person, who
  may be off shift.
- **13.** Starting over means up to a full cadence of calls to someone who
  already had an appointment. Treating it as hot gets a faster first call but
  puts it ahead of follow-ups that have waited longer.
- **15.** A new status appears in every list and filter of Leads. Without it,
  cancelled Leads look the same as booked ones until the Lead is opened.
- **16.** Letting agents record a cancellation matches who answers the phone,
  and it lets an agent take a Lead off a salesperson's calendar. Every
  cancellation is recorded with who did it.

## Readings the plan makes

Built this way unless Jacob says otherwise; listed in the plan under "Readings
this plan makes":

- A Lead that is sent back still counts as issued. Only "booked in error" takes
  an issue away.
- Sending back cancels an upcoming appointment in the same step.
- A Lead that is Appointment cancelled cannot be moved to another Location,
  returned to central or disqualified until it is sent back.
- An appointment whose time has passed can no longer be rescheduled, moved or
  cancelled.

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

# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-11 · **Branch:** `checkpoint-slice-7-plan`

**State:** a plan waiting for decisions. **Slice 7 is proposed and nothing of
it is built or approved.** It needs Jacob's answers to eleven questions,
below. This branch holds documents only.

Everything before it is merged into `main`: slice 6 (appointment booking), the
first check of the work against the plan, decisions 121 to 123, the passages
that check added to the plan, and the Appointments section on the customer
page (`17c259c`, merged on 2026-10-11 at Jacob's word). Both GitHub jobs passed on that merge (SQLite, and MySQL 8.4) and staging deployed it. It changed no table. Nobody has opened the staging pages in a browser.

## What Jacob decided on 2026-10-11, recorded on this branch

| Decision | What |
| --- | --- |
| 124 | Only a proposal has a result. An appointment shows as run and is never itself Sold, Pending or Lost; this corrects one line of decision 123. A proposal cannot be given a result before it exists. Demo Rate counts a run appointment against the salesperson until a proposal is created for it. Wins are counted against appointments, not proposals: three proposals with one sold is one win, and two accepted from one appointment is one win. |
| 124, also | Jacob is not to be asked whether a piece of work is next; he is to be told from the plan what is next and why. And his messages are dictated with Wispr Flow and arrive as pasted text; they are his own words. |

The plan's sections 5.2 and 5.6 carry dated passages saying the same.

## What is next, from the plan

**The after-action report (plan 5.6), built as slice 7.** The reasoning, in
full in [../slice-7-appointment-results-plan.md](../slice-7-appointment-results-plan.md):

- The plan's own path of a customer (section 20) runs "issued lead/calendar →
  confirmation → appointment → Pending/Rehash/Won/Lost". Slices 4 to 6 built it
  as far as the calendar.
- Confirmation comes first in that list and cannot be built: its process is
  open with Sales Leadership.
- So the next step that can be built is the appointment and its result. It is
  also what Pending and Rehash, Projects, commission and every Sales figure
  including NSLI read from.
- The booking engine needs Google Calendar and travel times, which the plan
  defers. The call center's popup, snooze tray and Aged Leads could be built
  now and improve something that works; Jacob can move them forward if he
  prefers.

**It cannot begin with the report's form,** because a result needs a proposal
and Backoffice has no proposal record. So it is built in the order the facts
depend on each other.

## The proposal, in short

| Step | Delivers |
| --- | --- |
| 7a | Say what happened to an appointment whose time has come: run, or no-show. A "To close out" list in Sales > Calendar of appointments still waiting for that answer. |
| 7b | Proposals: a simple record of what was pitched and for how much, tied to the appointment or appointments it was presented at. Demo Rate can then be counted. |
| 7c | A proposal's result: Pending, Sold (with the amount) or Lost (with a reason and the objection). The after-action report as one short form that does all of it from a phone. |
| 7d | The first Sales figures on Sales > Performance: Leads Issued, appointments run, Demo Rate, Close Rate, Sold Revenue, NSLI, Cancellation Rate. |

No new permission is proposed, no new package, nothing sent to a customer.

Not in slice 7: a Project when a sale is Sold; Pending running out and Rehash;
commission; the pricing engine, options, presentation, agreement and deposit;
the voice note; confirmation; Google Calendar; the booking engine.

## Decisions needed from Jacob

Each has a recommendation, so "agreed to all" is an answer. The plan gives the
alternatives.

| # | Question | Recommended |
| --- | --- | --- |
| 1 | Who says an appointment was run or a no-show, and from when? | The salesperson whose appointment it is, or a manager, from the appointment's start time. |
| 2 | What happens to a Lead after a no-show? | The same as a cancellation by the customer: it stays with the salesperson for the 14 days, then goes to the Cancelled Appointments queue, where the card says it was a no-show. It still counts as issued. |
| 3 | What does a Lead show once its appointment has been run? | A new status, "Appointment run", still with the salesperson. |
| 4 | What makes a proposal count for Demo Rate? | One saved with a name and a price and tied to that appointment. One started without a price does not count yet. |
| 5 | Can a proposal exist with no appointment? | Not in this slice. |
| 6 | Sold. | It records the sold amount and the day, and the Lead shows "Sold". Creating the Project is a later slice. |
| 7 | Lost. | The reason comes from the Sales Loss Reasons list as it stands today. |
| 8 | Objections. | A new managed list that starts with "Other (see note)", unless Jacob gives a starting list. |
| 9 | When is the Lead itself finished? | Sold when one of its proposals is Sold. Lost when the salesperson or a manager marks the Lead lost, which marks every proposal still Pending as Lost. |
| 10 | Close Rate when a sale takes two visits. | Count each Lead's run appointments as one, so a return visit that closes the sale does not lower the rate. The alternative is that every run appointment counts. |
| 11 | The voice note and its transcript. | Not in this slice: it needs private storage for audio and an outside transcription service. |

Tradeoffs worth knowing:

- **2.** Treating a no-show like a cancellation reuses everything slice 6
  built. It also means a no-show Lead waits 14 days with the salesperson
  before the call center calls it.
- **3 and 9.** Two new Lead statuses ("Appointment run", "Sold") and a way to
  mark a Lead lost. Each appears in every list and filter of Leads.
- **4.** A salesperson who writes "Exterior, price to follow" has not yet
  given a demo by this rule. That is what the plan's "draft estimate alone is
  not enough" asks for.
- **6.** Until Projects exist, a Sold Lead goes nowhere after Sales. It is
  recorded and counted, and Production does not see it.
- **10.** Jacob said wins are counted against appointments. Counting every
  visit lowers the rate of a salesperson who goes back to close; counting each
  Lead once does not, and matches how Leads Issued is counted for NSLI.

## The nine items the plan check set aside

Unchanged from the check of 2026-10-11, and none is in slice 7 except the
no-show (7a): the booking engine; confirming an appointment; Google Calendar;
the new-Lead popup and snooze tray; Aged Leads; the short-call report and
Connection Rate; call recordings; rehash. They stay listed in the Git history
of this file and are taken up when their part of the plan comes round, unless
Jacob moves one forward.

## Choices from slice 6 that stand unless Jacob changes them

- **6a.** The salesperson is told even when they booked their own Lead. Sales >
  Calendar shows all of a person's Locations at once. Opening the bell marks
  its notices seen.
- **6b.** The counting question defaults to "still counts" outside the Send
  back dialog. A Lead that came back and is booked again is a new issued Lead.
  Booking with a different salesperson tells only the new one. "Booked in
  error" is still offered on a passed appointment. "Other" is stored last in
  the reasons list.
- **6c.** The 14 days count from when the Lead became "Appointment cancelled"
  and start again on a take-back. Changing the number applies to Leads already
  waiting. The queue follows the appointment, not the manager's answer. A
  manager sees released Leads but cannot take one back for a salesperson.

## Tests and checks

Documents only. No application code, test or migration changed on this branch.
`composer test` was not run for it. The GitHub jobs are reported with the
branch, not recorded here.

## Browser review

None. Nothing on this branch changes a screen.

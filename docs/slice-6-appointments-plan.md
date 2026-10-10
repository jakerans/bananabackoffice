# Slice 6 — Appointment Booking: Proposed Plan

Status: **proposed on 2026-10-10. Not approved. Nothing in it is built.** It
follows the rulebook in
[slice-5-appointment-rulebook.md](slice-5-appointment-rulebook.md) (decisions 75
to 84, 88 and 89). Where the rulebook is silent this plan asks; the questions
are in "Decisions needed" and in [review/current.md](review/current.md). An
answer becomes a decision in [decisions.md](decisions.md) before anything is
built.

## What slice 6 is, and is not

Slice 6 lets staff book an appointment for a Qualified Lead with a salesperson.
Saving the appointment is the moment the Lead is **issued**: it is handed from
Contact Center to Sales (rulebook, rule 3).

It is built in three reviewed steps. Each stops for Jacob's review.

| Step | Delivers | Rulebook rules |
| --- | --- | --- |
| 6a | Book an appointment; the Lead is issued; "booked in error" undoes it; the salesperson is told; a plain list of appointments in Sales > Calendar | 1, 2, 3, 4, 5, 10 |
| 6b | Reschedule; move to another salesperson; the customer cancels, with the cancellation reasons list; a manager sends an issued Lead back to Contact Center | 6, 7, 8 (first two points), 9, 11 |
| 6c | A cancelled Lead that is not rebooked leaves the salesperson after 30 days and joins the Cancelled Appointments group; the call center re-issues it; the salesperson picks a released Lead back up | 8 (the rest), 12 |

Not in slice 6, each needing its own plan and approval:

- **Pending and Rehash** (rule 14). Pending is recorded by the after-action
  report, which does not exist.
- **Projects** (rule 13), the sales outcome, Sold Amount and commissions.
- **Google Calendar**, a calendar grid, drag and drop, customer reminders,
  no-shows and appointment outcomes.
- **Any message to a customer.** The only email is the one to the salesperson.
- Broadcasts and saved segments beyond the one Cancelled Appointments group.

## Step 6a — Book, issue, undo

### What a person sees

**On the Lead page**, a new "Appointment" card beside "Who has it":

- With no appointment: either a **Book appointment** button, or one line
  saying what is missing ("Qualify this lead first", "Send this lead to a
  location first", "Link a customer with a property address first"). The
  button shows only to someone who may book this Lead.
- The booking dialog asks for the **salesperson** (only people with Sales
  Consultant access at the Lead's Location; a Sales Consultant booking their
  own Lead sees only themselves), the **date**, the **time** and the **length**
  (90 minutes unless changed). The time is entered in the Location's own time
  and the dialog names that timezone.
- If the salesperson already has a Backoffice appointment overlapping that
  time, the dialog refuses and says which time is taken, without naming the
  other customer.
- After saving: the card shows the date and time, the salesperson, who booked
  it and when. The Lead shows the status **Appointment booked** and is assigned
  to the salesperson.
- A manager also sees **Booked in error** on the card. It asks for a note,
  then cancels the appointment, takes the Lead back from the salesperson and
  puts it back as it was before the booking (decision 7 below).

**In Sales > Calendar** (today "Coming soon"): a plain list of upcoming
appointments, grouped by day, soonest first. A salesperson sees their own. A
manager sees every salesperson's at their Locations. Each row opens the Lead.
No calendar grid.

**For the salesperson:** an email that says a new appointment is waiting, with
a link to it and no customer details; and a notice inside Backoffice (see
decision 2 below).

### What it stores

| Table | Holds |
| --- | --- |
| `appointments` | One row per appointment: the Lead, its Location, customer and property at the time; the salesperson; start, end and length; the Location timezone it was entered in; who booked it; its state (booked, or booked in error in 6a); when and by whom its state changed, with the note. A row is never deleted. |
| `lead_issues` | One row each time a Lead is issued: when, to which salesperson, by whom, for which appointment, and whether it was later voided by "booked in error". Never changed except to mark it voided. This is what "Leads Issued" is counted from. |
| `notifications` | Laravel's own table for notices inside the app, if decision 2 is answered that way. No new package. |

Also: a new Lead status `appointment_booked`; `leads.first_issued_at` and
`leads.last_issued_at`, set by the booking; and a new way for a Lead to leave
the call queue (the cadence ends with the reason "appointment booked").

"No double booking" is checked inside the booking's transaction with the
salesperson's row locked, so two bookings made at the same instant cannot both
be saved.

### Who may do what

Two new permissions, both checked at the Lead's Location:

| Permission | Lets a person | Roles |
| --- | --- | --- |
| `appointments.book` | Book an appointment for a Lead they may work | Owner, Leadership, Location Manager, Call Center Agent |
| `appointments.manage` | Mark an appointment "booked in error" (and, in 6b, move, send back) | Owner, Leadership, Location Manager |

A Sales Consultant holds neither. A policy lets them book **only a Lead already
assigned to them, and only for themselves** (rule 2, decision 89), and see
their own appointments. Being assigned never grants access: the existing Lead
rules still decide who may open the Lead.

### Audit and Activity

New audit events, each saved with its change: `lead.appointment_booked`,
`lead.appointment_booked_in_error`. Both also appear in the customer's Activity,
as "Appointment booked" and "Appointment booked in error".

### Tests

Feature tests for: each of the three conditions of rule 1; who may book and for
whom, as a matrix of roles and Locations; the salesperson must have access at
the Lead's Location; overlap refused, back-to-back allowed, a "booked in error"
appointment does not block the time; two bookings at the same instant (real
processes, as the queue tests do); everything in rule 3 happens together or not
at all; the Lead leaves the queue and a held Lead's hold ends; the email is
sent to the salesperson only and contains no name, phone, address or email of
the customer; "booked in error" needs a manager and a note, restores the Lead's
earlier status and assignee, voids the issue and puts the Lead back in the
queue where it stopped; the Calendar list shows only what the person may see;
and the migrations on MySQL 8.4.

## Step 6b — Change, cancel, send back (outline)

Reschedule keeps the salesperson and the issue, and keeps the old time in the
appointment's history. Moving to another salesperson is manager-only and tells
the new salesperson. A customer cancellation keeps the appointment as a
cancelled record with who cancelled and a reason from a new sixth managed list
on Lead & Sales Lists; the Lead stays with the salesperson. A manager can send
any issued Lead back to Contact Center with a note. This step is planned in
detail after 6a is reviewed, because several of its rules depend on decisions
8 to 10 below.

## Step 6c — Release and return (outline)

A daily timer releases a cancelled Lead that was not rebooked within 30 days
(an adjustable setting) to a Cancelled Appointments group the call queue can
be pointed at. The call center re-issues from it. A salesperson can take back a
Lead they were issued before. Planned in detail after 6b.

## Decisions needed

Needed before 6a can be built:

1. **Approve the three steps and what is left out,** above.
2. **How is the salesperson told inside the app?** Backoffice has no notices
   today. *Recommended:* a small bell in the header with a count, opening a
   list of notices, each a link. It is built once and reused by later work.
   *Alternative:* no bell; a "New" marker on the Sales > Calendar list only.
3. **Which timezone is an appointment in?** *Recommended:* the Lead's
   Location's own time, always labelled, as the call queue does.
4. **What may be booked?** *Recommended:* any future time, any day, in
   15-minute steps; length from 30 minutes to 4 hours in 15-minute steps,
   starting at 90. No calling-hours style limits, because the salesperson's
   real availability is not in Backoffice until Google Calendar is.
5. **Booking takes the Lead from whoever has it.** Rule 3 says the Lead is
   assigned to the salesperson. *Reading to confirm:* if the Lead was assigned
   to someone else, that assignment ends and is kept in the history, as any
   handoff does today.
6. **While an appointment stands, what do the existing actions do?** The Lead
   can be moved to another Location, returned to central or disqualified
   today, and its salesperson can lose access or be deactivated.
   *Recommended for 6a:* refuse to move, return or disqualify a Lead with an
   upcoming appointment, with the message "Cancel or undo its appointment
   first". If the salesperson loses access or is deactivated, the appointment
   stays on record, the Lead becomes unassigned as it does today, and the card
   says the appointment needs a salesperson, for a manager to fix.

7. **What does "booked in error" put back?** Rule 10 says the handoff is undone
   and the Lead returns to Contact Center. *Recommended:* exactly as it was a
   moment before the booking: Qualified, with whoever it was assigned to then
   (or nobody), and back in the call queue at the attempt it had reached if it
   was in the queue.

Needed before 6b and 6c, not before 6a:

8. **After a Lead goes back to Contact Center** (sent back, or released after
   30 days): which status does it show, and is it assigned to anyone?
   *Recommended:* Qualified and unassigned, with the Appointment card showing
   why it came back.
9. **Where does a returned Lead restart in the call cadence,** and does the
   Cancelled Appointments group share the one call queue or get its own
   button?
10. **A cancellation that does not come from the customer** (the salesperson or
   the company cancels): the same record and rules as rule 8, or different?
11. **Does a rebooking by the same salesperson after a cancellation** change
    the issued count? Rule 8 says only that the Lead still counts as issued.
    *Recommended:* no new count.

Still open with Sales Leadership and not needed for 6a: which salesperson an
issued Lead counts for after a move or re-issue; the cancellation reasons
themselves; offer validity; the Pending ownership period.

## Known limits of this plan

- The Calendar is a list, not a calendar. It does not show free time.
- Nothing checks the salesperson's personal calendar (rule 5).
- Appointment length is chosen for each booking; there is no settings page for
  the default yet.
- A booked appointment has no outcome. That comes with the after-action
  report.

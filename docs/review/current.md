# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-10 · **Branch:** `checkpoint-slice-6-appointments-plan` (documents only)

**State:** a planning report. Everything approved so far is built, merged into
`main` and deployed to staging: slices 1 to 4, the slice 5 rulebook, the MySQL
deadlock fix, the Call Queue display fixes, the manager-view switch for Call
Center Agents, and the fix that keeps a refusal on the Call Queue page. Their
handoffs are earlier versions of this file in the Git history.

Jacob asked on 2026-10-10 to start the next set of work. **This is the
implementation agent's proposal for it: slice 6, appointment booking.** Nothing
in it is approved or built. It needs the decisions below first.

## The proposal in one paragraph

Staff book an appointment for a Qualified Lead with a salesperson, from the Lead
page. Saving it issues the Lead: the Lead is assigned to the salesperson, shows
the new status "Appointment booked", leaves the call queue, the time it was
issued is kept for good, the customer's Activity gets an entry, and the
salesperson is told by an email with a link and no customer details and by a
notice inside Backoffice. Backoffice refuses to book a salesperson into a time
that overlaps another Backoffice appointment of theirs. A manager can mark a
booking "booked in error", which undoes it. Sales > Calendar becomes a plain
list of upcoming appointments.

The full plan is [../slice-6-appointments-plan.md](../slice-6-appointments-plan.md).
It follows the fourteen rules in
[../slice-5-appointment-rulebook.md](../slice-5-appointment-rulebook.md).

## The three steps proposed

| Step | Delivers |
| --- | --- |
| 6a | Book an appointment and issue the Lead; "booked in error"; telling the salesperson; the Sales > Calendar list |
| 6b | Reschedule; move to another salesperson; the customer cancels, with a cancellation reasons list; a manager sends an issued Lead back to Contact Center |
| 6c | A cancelled Lead not rebooked within 30 days leaves the salesperson and joins the Cancelled Appointments group; the call center re-issues it; a salesperson picks a released Lead back up |

Left out of slice 6, each needing its own plan: Pending and Rehash, Projects and
the sales outcome, commissions, Google Calendar, a calendar grid, no-shows and
appointment outcomes, and any message to a customer.

## Decisions needed from Jacob before 6a

1. **Approve the three steps and what is left out.** Recommendation: approve.
   6a is the smallest piece that is useful by itself and can be undone by a
   manager.
2. **How is the salesperson told inside the app?** Backoffice shows no notices
   today.
   - *Recommended:* a small bell in the header with a count, opening a list of
     notices that each link somewhere. Built once, reused by later work.
   - *Alternative:* no bell; a "New" marker on the Sales > Calendar list only.
     Less to build, but the salesperson only sees it if they open that page.
3. **Which timezone is an appointment in?** Recommendation: the Lead's
   Location's own time, always labelled, as the call queue does.
4. **What may be booked?** Recommendation: any future time on any day, in
   15-minute steps; a length from 30 minutes to 4 hours, starting at 90. No
   working-hours limit, because the salesperson's real availability is not in
   Backoffice until Google Calendar is.
5. **Booking takes the Lead from whoever has it.** A reading to confirm: if the
   Lead was assigned to someone else, that assignment ends and stays in the
   history, as any handoff does today.
6. **While an appointment stands, what do the existing actions do?** Today a
   Lead can be moved to another Location, returned to central or disqualified,
   and its salesperson can lose access or be deactivated.
   - *Recommended:* refuse to move, return or disqualify a Lead that has an
     upcoming appointment, with "Cancel or undo its appointment first". If the
     salesperson loses access or is deactivated, the appointment stays on
     record, the Lead becomes unassigned as it does today, and the Lead says
     the appointment needs a salesperson, for a manager to fix.
   - *Tradeoff:* until 6b brings cancelling, the only way to free such a Lead
     is "booked in error", which a manager must do.
7. **What does "booked in error" put back?** Recommendation: the Lead exactly
   as it was a moment before the booking: Qualified, with whoever it was
   assigned to then (or nobody), and back in the call queue at the attempt it
   had reached, if it was in the queue.

Two new permissions come with 6a and are approved with the plan:
`appointments.book` (Owner, Leadership, Location Manager, Call Center Agent)
and `appointments.manage` (Owner, Leadership, Location Manager). A Sales
Consultant holds neither and may book only a Lead already assigned to them, for
themselves.

## Decisions needed later, before 6b and 6c

8. After a Lead goes back to Contact Center, which status does it show and is
   it assigned to anyone? Recommendation: Qualified and unassigned.
9. Where does a returned Lead restart in the call cadence, and does the
   Cancelled Appointments group share the one call queue or get its own button?
10. A cancellation that does not come from the customer: the same record and
    rules as a customer's, or different?
11. Does rebooking by the same salesperson after a cancellation change the
    issued count? Recommendation: no.

Still with Sales Leadership and not needed for 6a: which salesperson an issued
Lead counts for after a move or re-issue; the cancellation reasons themselves;
offer validity; the Pending ownership period.

## What the screens would be (6a)

- **Lead page:** a new "Appointment" card beside "Who has it". It shows a Book
  appointment button, or one line saying what is missing (not qualified, not at
  a Location, no customer with a property address). After booking it shows the
  date and time in the Location's time, the salesperson, and who booked it. A
  manager also sees Booked in error.
- **Booking dialog:** salesperson, date, time, length. It names the timezone.
  If the time overlaps another appointment of that salesperson it says which
  time is taken and saves nothing; it never names the other customer.
- **Sales > Calendar:** a list of upcoming appointments by day. A salesperson
  sees their own; a manager sees their Locations'. Each row opens the Lead.
- **Header:** the bell, if decision 2 is answered that way.

## Other ways to spend the next set

This proposal picks appointments because it is the next link after the call
queue and its rulebook is written. The alternatives named earlier are still
open, and Jacob may prefer one:

- **Production readiness.** No production environment exists. The documents
  list what it needs before real customer data: its own server and database
  account, backups and a restore drill, its own email settings, the real
  domain, and decisions on two-factor sign-in and on impersonation.
- **Projects and the sales outcome.** These now wait on the after-action
  report (rulebook, rule 13), so they come after appointments either way.

## Checks on this change

Documents only. No code changed, so the full local check was not run again; it
last passed on this same code on 2026-10-10 (2,018 tests). The two GitHub jobs
for this branch are reported with it and not recorded here.

## Still open from earlier work

- Nobody has opened the staging pages in a browser since the last four merges.
- The confirmation message after changing a manager-view switch was not seen
  in the browser look; the switch itself saved.
- Open follow-ups from step 4c, none authorized: the held and cadence-finished
  lists stop at 25 with no notice; the card shows the Lead's inquiry phone
  while the attempt form pre-fills the linked contact's phone; losing access
  while the Skip dialog is open shows a "forbidden" page.
- One step 4b test depends on timing and failed once locally on 2026-10-09
  ("The claim never got in first"); it passed in every other run.
- Local database only: seven seeded fictitious staff, three Locations (one set
  to Pacific) and fictitious Leads named "Queue Review … (Sample)".

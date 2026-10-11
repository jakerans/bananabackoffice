# Slice 6 — Appointment Booking: Proposed Plan

Status: **the three steps are approved in order (2026-10-10, decisions 90 to
96). Step 6a is built and merged into `main` (2026-10-10); see "Step 6a as
built" below. Step 6b is built and merged into `main` (2026-10-10); see "Step
6b as built" below. Step 6c is built and merged into `main` (2026-10-10); see
"Step 6c as built" below. Slice 6 is complete.** Jacob answered every
question the 6c plan asked (decisions 115 to 120).
Jacob answered every question the 6b plan asked on 2026-10-10 (decisions 97 to
114). It follows the rulebook in
[slice-5-appointment-rulebook.md](slice-5-appointment-rulebook.md) (decisions 75
to 84, 88 and 89). Where the rulebook is silent this plan asks; an answer
becomes a decision in [decisions.md](decisions.md) before anything is built.

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

### Step 6a as built (2026-10-10)

Built as planned above. What a reader of the code should know:

- **Booking** is `app/Actions/Appointments/BookAppointment.php`; "booked in
  error" is `MarkAppointmentBookedInError.php` beside it. Both go through
  `ChangeLeadLifecycle`, so a Lead that changed since the dialog opened is
  refused, and both run again from the start on a deadlock (decision 87).
- **No double booking.** The salesperson's row is locked for the length of the
  booking and their other standing appointments are read with a locking read,
  so two bookings made at the same moment are decided one after the other.
  Appointments that only touch, one ending as the next starts, are allowed.
- **Tables:** `appointments`, `lead_issues`, `notifications` (Laravel's own),
  and `leads.first_issued_at` and `leads.last_issued_at`. An appointment and an
  issue are never deleted. "Booked in error" marks the issue voided and works
  the Lead's two issue times out again from the issues that still count.
- **The Lead leaves the call queue** with a new cadence state, "appointment
  booked". The timer's repair pass treats a booked Lead still in the queue the
  way it treats a disqualified one. "Booked in error" puts back only a Lead
  the booking itself took out, at the attempt it had reached, due at the next
  opening of calling hours.
- **The screens:** the Appointment card is its own component,
  `resources/views/pages/leads/⚡appointment.blade.php`, shown on the Lead page
  and telling it when to read the Lead again. Sales > Calendar is
  `pages/sales/⚡calendar.blade.php`. The bell is
  `pages/notifications/⚡bell.blade.php`, in the header of every page.
- **Telling the salesperson:** the notice inside the app is saved with the
  booking, in the same transaction. The email is sent once the booking is
  saved; if it cannot be sent the booking stays, the failure is reported, and
  the person booking is told the email did not go.

Readings made while building, none of them a new rule:

- **The salesperson is always told,** also when they booked their own Lead.
  Rule 3 says the salesperson is notified and makes no exception.
- **Sales > Calendar shows every Location the person may see at once.** It does
  not follow the Location chosen in the header; each row names its Location
  and shows that Location's time.
- **A Lead whose appointment time has passed** stays Appointment Booked. Its
  appointment no longer blocks a move or a disqualification, but the Lead page
  offers no lifecycle button for that status yet: what happened at the
  appointment belongs to the after-action report.
- **"Booked in error" is offered only while the Lead is still Appointment
  Booked.**
- **Opening a notice list marks its notices seen,** except while an Owner is
  viewing Backoffice as that person.

## Step 6b — Change, cancel, send back (built 2026-10-10)

Planned on 2026-10-10 after step 6a was reviewed and merged, and built the same
day once Jacob had answered every question (decisions 97 to 114). It follows
rules 6, 7, 8 (its first two points), 9 and 11. The lines below still name the
questions they rested on; each question's answer is under "Decisions needed",
and what was built is under "Step 6b as built".

### What a person sees

**On the Lead page's Appointment card,** while an appointment is upcoming:

- **Reschedule.** For the salesperson whose appointment it is, or a manager
  (rule 6). It asks for the day, the time and the length, with the same rules
  as booking (decision 93) and the same refusal of an overlap. The salesperson
  does not change. The card shows the new time, and the earlier times under
  "Earlier times" with who changed each and when.
- **Move to another salesperson.** For a manager only (rule 7). It offers the
  other people with Sales Consultant access at the Lead's Location and refuses
  one who already has an appointment then. The Lead is assigned to the new
  salesperson; the card and the history show both people. This is also how a
  manager fixes an appointment that "needs a salesperson" because its
  salesperson lost access (decision 95).
- **Cancel appointment.** It asks **who cancelled**, a **reason** from the new
  Cancellation Reasons list, and an optional note. The appointment stays on
  the card as a cancelled record with all three, who recorded it and when. The
  Lead stays with the salesperson and still counts as issued (rule 8). Who may
  record a cancellation is question 16.
- **After a cancellation:** the Lead shows a new status, **Appointment
  cancelled** (question 15), and the card offers **Book appointment** again.
  Booking it again with the same salesperson adds no issued count (decision
  99). Booking it with a different salesperson is for a manager only (question
  18).
- **Send back to Contact Center.** For a manager only, with a note (rule 9), on
  any Lead that is with Sales: Appointment booked or Appointment cancelled. It
  asks **why**, because the reason decides whether the Lead still counts as
  issued (decision 100, question 19). What happens to an appointment that is
  still upcoming is question 20. The Lead shows Qualified again (decision 97),
  is assigned to nobody (decision 103), and re-enters the call queue where
  question 13 says.
- **A Lead whose appointment was cancelled** can also be moved to another
  Location, returned to central or disqualified with the buttons the Lead page
  already has (decision 101). What it looks like afterwards is question 21.
- **After the appointment time has passed** the appointment can no longer be
  rescheduled, moved or cancelled (decision 102). The card offers **Book
  another appointment** instead, which is the path for a missed appointment
  (question 22).
- **Booked in error** stays as it is, for a standing appointment only.

**In Sales > Calendar:** a cancelled appointment leaves the upcoming list. A
second list, **To rebook**, shows the cancelled Leads still waiting for a new
appointment, with the day each was cancelled. A salesperson sees their own; a
manager sees every salesperson's at their Locations.

**On Lead & Sales Lists:** a new list, **Cancellation Reasons**, managed like
the others (rule 11, decision 30). What it starts with is question 17.

**Who is told,** by the bell built in 6a. An email goes only where a
salesperson gains an appointment or its time changes, and carries a link and no
customer details (rule 4):

| What happened | Who is told | Bell | Email |
| --- | --- | --- | --- |
| Rescheduled by someone else | The salesperson | Yes | Yes |
| Rescheduled by the salesperson themselves | Nobody | | |
| Moved to another salesperson | The new salesperson | Yes | Yes |
| Moved to another salesperson | The earlier salesperson | Yes | No |
| Cancelled, recorded by someone else | The salesperson | Yes | No |
| Sent back to Contact Center | The salesperson it was with | Yes | No |

### What it stores

| Table | Change |
| --- | --- |
| `appointments` | A new state, **cancelled**, with who cancelled (customer, salesperson or company; decision 104), the reason, the note, and who recorded it and when. A rebooking is a new row that points at the cancelled one it follows. Still never deleted. |
| `appointment_changes` (new) | One row for each reschedule or move: the time and salesperson before and after, who did it, when, and the note. Never changed or deleted. This is the "history" rules 6 and 7 ask for. |
| `appointment_cancellation_reasons` (new) | The managed list, built like the existing six. |
| `leads` | The new status `appointment_cancelled`, if question 15 is agreed. |
| `lead_issues` | A reschedule, a move, a cancellation and a rebooking with the same salesperson write no row and void none. **A Lead that leaves Sales because it should never have been booked has its issue voided** (decision 100), the same mark "booked in error" makes, with who and when. Which salesperson an issue counts for after a move is still open with Sales Leadership; the history kept here lets either answer be counted later. |

Every action goes through the same door as booking: the Lead must not have
changed since the dialog opened, the salesperson's row is locked so two changes
at the same moment are decided one after the other, and each change is saved
with its history, its audit event and its notices together or not at all.

### Who may do what

No new permission. All checked at the Lead's Location.

| Action | Who |
| --- | --- |
| Reschedule | The salesperson whose appointment it is; a manager (`appointments.manage`) |
| Move to another salesperson | A manager |
| Record a cancellation | Question 16. *Recommended:* anyone who may book that Lead: a manager, a Call Center Agent, and the salesperson whose appointment it is |
| Rebook after a cancellation, same salesperson | Anyone who may book that Lead |
| Rebook after a cancellation, different salesperson | A manager (question 18) |
| Send back to Contact Center | A manager |
| Say a Lead leaving Sales "should never have been booked", so it does not count as issued | A manager (question 19) |
| Move a cancelled Lead to another Location, return it to central, or disqualify it | Whoever may do each today; nothing changes (decision 101) |
| Book another appointment after one has passed | As for a rebooking after a cancellation (question 22) |

Decision 88 already says a Call Center Agent cannot reschedule, move, send back
or mark "booked in error". It does not mention recording a cancellation, which
is why question 16 asks.

### Audit and Activity

New audit events, each saved with its change: `lead.appointment_rescheduled`,
`lead.appointment_moved`, `lead.appointment_cancelled`,
`lead.appointment_rebooked`, `lead.sent_back_to_contact_center`. Each also
appears in the customer's Activity in plain words.

### Readings this plan makes

The first version of this plan listed four readings. **Jacob answered them on
2026-10-10:**

| Reading as first written | Jacob's answer |
| --- | --- |
| A Lead that is sent back still counts as issued. | **Changed (decision 100).** It depends on the reason: a Lead that should never have been booked does not count. Question 19. |
| Sending back cancels an upcoming appointment in the same step. | **Not decided.** He is thinking about it: if the appointment simply does not work for the salesperson, a manager may move it to someone else instead. Question 20. |
| A Lead that is Appointment cancelled cannot be moved, returned to central or disqualified until it is sent back. | **Reversed (decision 101).** It can be, for example when it was cancelled for the wrong service area. Question 21. |
| An appointment whose time has passed can no longer be rescheduled, moved or cancelled. | **Agreed, with a condition (decision 102):** a missed appointment needs a clear way to be booked again. Question 22. |

One reading is left. Built this way unless Jacob says otherwise:

- **A reschedule or a move to a time already past is refused,** as a booking
  is.

### Tests

Feature tests for: reschedule by the salesperson and by a manager, refused for
anyone else, overlap refused, the old time kept, the issue unchanged; a move
needs a manager, the new salesperson must have access there and be free, both
people are in the history, each is told as the table says; a cancellation
keeps the record with who, reason and note, leaves the Lead with the
salesperson and the issue counted, and frees the time for another booking; who
may record one, as a matrix of roles and Locations; rebooking with the same
salesperson writes no issue and with a different one needs a manager; send
back needs a manager, a reason and a note, returns the Lead as decisions 97 and
103 and question 13 say, and voids the issue only for "should never have been
booked" (decision 100); a cancelled Lead can be moved, returned to central and
disqualified, and a Lead with an upcoming appointment still cannot (decisions
95 and 101); a passed appointment cannot be changed and another can be booked
after it with no new count (decision 102); the Cancellation Reasons list under
the existing managed-list rules; the Calendar's two lists show only what the
person may see; no notice or email carries a customer's details; two changes
to the same salesperson's time at the same instant (real processes); and the
migrations on MySQL 8.4.

### Not in 6b

The 30-day release, the Cancelled Appointments group and its button (decision
98), a re-issue by the call center of a released Lead, and a salesperson
picking a released Lead back up. All are 6c.

### Step 6b as built (2026-10-10)

Built as planned above, with the answers in decisions 97 to 114. What a reader
of the code should know:

- **The actions** are in `app/Actions/Appointments/`: `RescheduleAppointment`,
  `MoveAppointment`, `CancelAppointment` and `SendLeadBackToContactCenter`,
  beside 6a's two. Each goes through `ChangeLeadLifecycle`, so a Lead that
  changed since the dialog opened is refused, and each runs again from the
  start on a deadlock (decision 87).
- **Time rules live in one place,** `app/Support/Appointments/AppointmentSlot.php`:
  what may be typed (decision 93) and "no double booking" (rule 5). Booking,
  rescheduling and moving all use it. The salesperson's row is locked first and
  the appointment's own row written after, the order a booking takes them in.
- **Booking again.** `BookAppointment` now accepts a Lead that is with Sales
  (its appointment was cancelled, or its time has passed). Such a booking
  writes no issue, leaves the Lead's issue times alone, and points at the
  appointment it follows (`appointments.follows_appointment_id`). With a
  different salesperson it takes a manager (decision 109). A Lead with an
  upcoming appointment is refused: "Reschedule that one instead."
- **A Lead leaving Sales** is one piece of code,
  `app/Actions/Appointments/ReturnLeadFromSales.php`, used by Send back and by
  the existing Move to another Location and Return to central when the Lead is
  with Sales (decision 112). It cancels an upcoming appointment, unassigns the
  Lead, sets it Qualified, marks when it came back
  (`leads.returned_from_sales_at`), restarts the cadence and tells the
  salesperson. `StopCountingLeadAsIssued` voids the issue when a manager says
  it should never have been booked (decision 110); it is asked before the Lead
  moves, at the Location it is leaving.
- **The cadence restarts as a new cycle,** never cycle 1 with no attempts, so a
  returned Lead is handed out among follow-up calls and not as a Lead nobody
  has called (decision 105). A Lead that never had a cadence is given one the
  same way. The timer's repair pass treats Appointment cancelled like
  Appointment booked.
- **Disqualifying inside a cancellation** (decision 108) calls
  `DisqualifyLead::apply`, the disqualification without its own claim on the
  Lead, inside the cancellation's transaction. A duplicate cannot be recorded
  that way, because it needs its original named.
- **Tables:** `appointment_changes`, `appointment_cancellation_reasons`, four
  columns on `appointments` and one on `leads`. A fifth migration widens the
  three columns that keep a copy of a Lead's status
  (`lead_status_changes.from_status` and `to_status`,
  `contact_attempts.lead_status`) from twenty characters to forty: the new
  status is twenty-one, which MySQL refused and SQLite did not notice.
- **The screens:** the Appointment card (`pages/leads/⚡appointment.blade.php`)
  holds the new dialogs. The Lead page re-reads the card whenever it changes
  the Lead itself. Two small shared pieces: `components/lead/sales-return`
  (the "came back from Sales" notice, on the card and on the Call Queue card)
  and `components/lead/issued-count` (the counting question in the Lead
  page's Move, Return and Disqualify dialogs).

Readings made while building, none of them a new rule:

- **The counting question defaults to "still counts"** in the Move, Return and
  Disqualify dialogs, and must be answered in the Send back dialog. Someone
  who is not a manager is not asked, and is refused if they send the answer
  anyway.
- **One audit event, `lead.returned_from_sales`,** for a Lead that goes back
  by any of the three ways, saying which. The plan named
  `lead.sent_back_to_contact_center`; one name serves the customer's Activity
  better. A move or a return also writes its usual event.
- **Booking a Lead with Sales with a different salesperson** tells the new
  salesperson, as any booking does. The earlier one is not told.
- **"Booked in error" on a rebooking** puts the Lead back as it was before
  that booking (Appointment cancelled, with its salesperson) and leaves its
  issue counted.
- **A Lead that came back and is booked again is a new issue.** It left Sales
  and was issued again: a re-issue, recorded with full history (rule 8). If
  its first issue still counts, the Lead has two. Which salesperson each counts
  for is still open with Sales Leadership.
- **The order of the Cancellation Reasons list:** Jacob listed "Other" first.
  It is stored last, as the other lists keep theirs, and can be reordered.

## Step 6c — Release and return (built 2026-10-10)

Planned on 2026-10-10 after step 6b was merged, and built the same day once
Jacob had answered every question (decisions 115 to 120). It follows rule 8
(its last three points) and rule 12, and decisions 98 and 114. **Where the
lines below say 30 days, the waiting period is 14 days (decision 119).** What
was built is under "Step 6c as built".

### What a person sees

**The salesperson**, in Sales > Calendar under "To rebook": each cancelled
Lead now says how many days are left before it goes back to the call center.
A Lead that has gone back moves to a third list, **Released from you**, for as
long as it can still be taken back.

**After 30 days** (an adjustable setting, question 26) with no new
appointment, the timer takes the Lead from the salesperson. It is then exactly
as a Lead a manager sent back: Qualified, with nobody, marked as having come
back from Sales, and still counted as issued (rule 8). The salesperson is told
by the bell (question 27).

**The call center**, on the Call Queue: a second button, **Next cancelled
appointment**, with its own count, beside "Next lead" (decision 98, question
24). The Lead it hands out carries the "Cancelled appointment: this lead came
back from Sales" notice built in 6b, so the agent knows which script to use
before they dial. Which Leads are in this group is question 23.

**Booking one from the group** is an ordinary booking: any salesperson rule 2
allows, a new issued Lead with its full history kept (rule 8, "re-issue"), and
the Lead leaves the group.

**Taking a Lead back** (rule 12): the salesperson it was released from sees
**Take back** on the Lead page and in "Released from you". It asks for a short
note. The Lead returns to them as Appointment cancelled, to be booked, with
the same history and no new issued count; it leaves the call queue, and an
agent who was holding it is told why. Who may do this, and for how long, is
question 28. If the Lead has already been booked with someone else, only a
manager can move it (rule 7), which 6b already built.

**Contact Center settings** gain one number: the days a cancelled Lead stays
with its salesperson.

### What it stores

| Table | Change |
| --- | --- |
| `lead_cadences` | Which queue the Lead is in: the ordinary one or Cancelled Appointments. Each "Next" button reads only its own. |
| `leads` | Who the Lead was released from and when, so "Released from you" and Take back know whose it was. Cleared when it is booked or disqualified. |
| `contact_center_settings` | The number of days, kept with the settings' history like the other values. |

No new permission is proposed. The release is done by the timer that already
runs every minute; it writes the same history, audit event and notice as a
manager's Send back, saying it was the timer.

### Readings this plan makes

Built this way unless Jacob says otherwise. None is a new rule.

- **A released Lead still counts as issued,** and booking it from the group is
  a new issue (rule 8). A Lead taken back by its salesperson is not (rule 12).
- **The 30 days are counted from the latest cancellation.** A Lead cancelled,
  rebooked and cancelled again starts a new 30 days.
- **A Lead with no salesperson** (theirs lost access) is still released at 30
  days; there is nobody to tell.
- **A Lead is not released while an appointment is upcoming,** and one that is
  booked again in time simply never is.

### Tests

Feature tests for: release at exactly the configured number of days and not
before; a Lead rebooked in time is never released; the setting's limits and
who may change it; what a released Lead looks like, including its issue; the
salesperson is told; "Next cancelled appointment" hands out only that group
and "Next lead" never does, with both counts right and Location and calling
hours respected as today; two agents pressing the new button at the same
moment (real processes); booking from the group is a new issue; Take back
needs the right person and a note, adds no count, ends an agent's hold, and is
refused once the Lead has been booked with someone else; the Calendar's three
lists show only what the person may see; and the migrations on MySQL 8.4,
with every new stored value checked against the length of its column.

### Not in 6c

Pending and Rehash (rule 14), reactivation texts or emails to the Cancelled
Appointments group, a calendar grid, and whether a passed appointment was held
or missed.

### Step 6c as built (2026-10-10)

Built as planned above, with the answers in decisions 115 to 120. What a
reader of the code should know:

- **Two queues.** `lead_cadences.queue` says which a Lead is in
  (`App\Enums\CallQueue`: main, or cancelled appointments). `ClaimNextLead`
  and `DueQueue` take the queue and read only that one, so neither button can
  hand out the other's Leads. In Cancelled Appointments the Lead that came
  back most recently goes first. A person still holds one Lead at a time,
  whichever button it came from.
- **Which queue a returned Lead joins** is decided in one place,
  `ResumeLeadCadence::afterReturnFromSales`: Cancelled Appointments when its
  latest appointment is cancelled, the ordinary queue otherwise. This is what
  changed for a manager's send-back (decision 117).
- **The release** is `app/Actions/Appointments/ReleaseCancelledLead.php`, run
  by the every-minute timer (`RunQueueTick`), which now also warns. A released
  Lead goes through the same `ReturnLeadFromSales` as a Lead sent back, with
  nobody recorded as having done it, and remembers who it was released from
  (`leads.released_from_user_id`, `released_from_sales_at`).
- **The wait is counted from when the Lead became Appointment cancelled**
  (`leads.status_changed_at`), not from the appointment's own record, so it
  starts again when a salesperson takes a Lead back. The number of days is
  read each time the timer looks, so a change applies to Leads already
  waiting.
- **The warning** is sent once per wait (`leads.release_warned_at`), five days
  before the release. A waiting period of five days or fewer has none.
- **Take back** is `TakeLeadBackFromContactCenter`, allowed by
  `LeadPolicy::takeBack`. The Lead returns as Appointment cancelled with its
  salesperson, its cadence ends with the new reason "taken back by sales", and
  an agent holding it is told.
- **The setting** is `contact_center_settings.cancelled_release_days`, saved
  like the other sections of that page: a new version each time, with an audit
  event (`contact_center.release_days_changed`).
- **Tables:** one column on `lead_cadences`, three on `leads`, one on
  `contact_center_settings`. Three migrations. Every new stored name was
  checked against the length of the column that holds it.

Readings made while building, none of them a new rule:

- **A Lead sent back as "should never have been booked" with a cancelled
  appointment still goes into Cancelled Appointments.** The queue is chosen by
  the appointment, not by the manager's answer; the manager's note is on the
  notice the agent reads.
- **Leads already in the ordinary queue when this is deployed stay there,**
  including any that came back from Sales under step 6b. Only Leads that come
  back from now on are sorted.
- **A manager sees every released Lead at their Locations** in Sales >
  Calendar, with whose it was. Only the salesperson can take it back; the
  manager's way is to book or assign it.
- **The Call Queue page's manager summary** shows the cancelled appointments
  due as a second figure under "Due now". Held and finished Leads are listed
  together for both queues.
- **The timer does this work itself.** Everything else it does is a repair of
  something already decided; the release is the one thing only it does. If the
  timer stops, Leads stay with their salespeople until it runs again.

## Decisions needed

Asked before 6a. **Jacob agreed to every recommendation on 2026-10-10; they are
decisions 90 to 96, in this order:**

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

Asked before 6b and 6c. **Jacob answered 8, 9 and 11 on 2026-10-10 (decisions
97 to 99), and 12 and 14 the same day (decisions 103 and 104).** What is left
of them is question 13.

8. **After a Lead goes back to Contact Center** (sent back, or released after
   30 days): which status does it show, and is it assigned to anyone?
   **Answered (decisions 97 and 103):** it shows Qualified and is assigned to
   nobody.
9. **Where does a returned Lead restart in the call cadence,** and does the
   Cancelled Appointments group share the one call queue or get its own
   button? **Answered in part (decision 98):** its own button, worked as hot
   Leads beside new Leads. Where a Lead restarts is question 13.
10. **A cancellation that does not come from the customer.** **Answered
    (decision 104):** the same record and rules; see question 14.
11. **Does a rebooking by the same salesperson after a cancellation** change
    the issued count? **Answered (decision 99):** no. It can be rebooked with
    the same salesperson and adds no count.

**Asked before step 6b was approved. All are answered (2026-10-10):** 12 and
14 by decisions 103 and 104; 13 by decision 105 (the ordinary call queue, from
attempt 1); 15 by 106; 16 by 107; 17 by 108 (Jacob's own list, and "Other"
disqualifies the Lead when answered yes); 18 by 109; 19 by 110; 20 by 111; 21
by 112; 22 by 113. Jacob agreed to the recommendation on each of 15, 16 and 18
to 22. Decision 114 (the call center is told a Lead came back from Sales) was
added while building.

12. **Who has a Lead after it comes back to Contact Center?** Jacob's answer to
    question 8 read "assigned to somebody"; the recommendation had been
    "nobody". **Answered (decision 103): nobody.** The call queue hands it to
    the next available person, as it does every Lead, and the Appointment card
    says why it came back.
13. **Where does a Lead a manager sends back restart in the call cadence?**
    Rule 9 says it re-enters the call queue. *Recommended:* it starts the
    cadence over at attempt 1, due at the next opening of calling hours, in
    the ordinary queue, in line with follow-up calls by how long each has
    waited. *Alternative:* treat it as hot, in line with new Leads.
14. **A cancellation that does not come from the customer** (the salesperson
    or the company cancels). **Answered (decision 104):** the same record and the same rules
    as rule 8. "Who cancelled" offers three choices: the customer, the
    salesperson, the company. The Lead stays with the salesperson, still
    counts as issued, and the 30 days run the same way.
15. **What does a Lead show after its appointment is cancelled,** while it is
    still with the salesperson? "Appointment booked" would no longer be true.
    *Recommended:* a new status, **Appointment cancelled**, so the salesperson
    and their manager can see at a glance which Leads need rebooking.
    Rebooking returns it to Appointment booked. *Alternative:* it stays
    Appointment booked and only the card says cancelled.
16. **Who may record a cancellation?** *Recommended:* anyone who may book that
    Lead: a manager, a Call Center Agent (customers often call the call center
    to cancel), and the salesperson whose appointment it is. *Alternative:*
    the salesperson and managers only.
17. **What does the Cancellation Reasons list start with?** The reasons
    themselves are still open with Sales Leadership (rule 11), and a
    cancellation needs one. *Recommended:* one entry, "Other (see note)", so
    the feature works on its first day; Owner and Leadership add the real ones
    on Lead & Sales Lists when they have them. *Alternative:* Jacob gives the
    starting list now.
18. **Rebooking with a different salesperson while the Lead is still with the
    first one** (inside the 30 days). *Recommended:* a manager only, recorded
    as a move (rule 7). A Call Center Agent and the salesperson can rebook
    with the same salesperson only. After the 30-day release, in 6c, the call
    center may choose anyone (rule 8).

Raised by Jacob's answers to the plan's readings (decisions 100 to 102):

19. **How does a person say whether a Lead leaving Sales still counts as
    issued?** Decision 100 makes it depend on the reason. *Recommended:* the
    Send back dialog asks one required question with two answers, and a note:
    **"It should never have been booked"** (the issue no longer counts, as
    "booked in error" does today) or **"Another reason"** (it still counts).
    The same question is asked when a Lead that is with Sales is disqualified.
    Only a manager may give the first answer, because rule 10 keeps taking an
    issue away manager-only; when anyone else disqualifies such a Lead it
    stays counted, and a manager can correct it afterwards. *Alternative:* a
    longer list of send-back reasons that Jacob names, each marked as counting
    or not.
20. **Sending back a Lead whose appointment is still upcoming.** Jacob is
    thinking about this: the salesperson may be sending it back only because
    the time does not work for them, and then a manager would give the
    appointment to someone else. *Recommended:* keep the two actions apart.
    **Move to another salesperson** keeps the appointment and is the answer
    when the appointment should still happen. **Send back to Contact Center**
    is for a Lead the call center must work again, so it always cancels an
    upcoming appointment, and its dialog says "If someone else should take
    this appointment, use Move to another salesperson instead." A salesperson
    cannot send a Lead back themselves; they ask a manager, who chooses.
    *Alternative:* a way for the salesperson to ask, inside Backoffice, for a
    manager to move or take back an appointment. That is a new feature and
    would be its own step.
21. **What happens to a cancelled Lead that is moved to another Location or
    returned to central?** Decision 101 allows it. A move already takes a Lead
    from whoever has it (decision 44). *Recommended:* it leaves the
    salesperson and from then on is treated exactly as a Lead sent back:
    Qualified and unassigned (decisions 97 and 103), back in the call
    queue as question 13 says, and the person moving it is asked the same
    counting question as in 19. The cancelled appointment stays on its record.
    A cancelled Lead that is disqualified is simply Disqualified, with the
    same counting question.
22. **The path after a missed appointment** (decision 102). *Recommended:* once
    an appointment's time has passed, the card offers **Book another
    appointment**. It follows the rules for rebooking after a cancellation:
    with the same salesperson by anyone who may book the Lead, with a
    different salesperson by a manager, and no new issued count. The passed
    appointment stays on the card as it was; Backoffice does not yet record
    whether it was held or missed, because that belongs to the after-action
    report (decision 84). *Alternative:* also let the passed appointment be
    marked "Missed" now. That starts recording outcomes ahead of the
    after-action report.

**Asked before step 6c was approved. All are answered (2026-10-10):** 23 and
24 by decision 117; 25 by 115; 26 by 116, with the starting value changed to
14 days by 119; 27 by 118; 28 by 120.

23. **Which Leads belong in Cancelled Appointments?** *Recommended:* every
    Lead that comes back to the Contact Center with its last appointment
    cancelled: released at 30 days, sent back by a manager, or moved to
    another Location. They need the same script (decision 114), so they belong
    behind the same button. A Lead that comes back after an appointment that
    took place or was missed stays in the ordinary queue. This would change
    decision 105 for the Leads a manager sends back with a cancelled
    appointment. *Alternative:* only Leads released at 30 days; a manager's
    send-back stays in the ordinary queue as built.
24. **How does the button work "beside new Leads"** (decision 98)?
    *Recommended:* two buttons side by side, each with its own count: "Next
    lead" and "Next cancelled appointment". "Next lead" never hands out a
    cancelled appointment, so the agent always knows which script they are
    about to use. Within the group, the Lead that came back most recently is
    handed out first. *Alternative:* one button that hands out new Leads, then
    cancelled appointments, then follow-ups, with the notice telling the agent
    which it is.
25. **How often is a Lead in the group called?** **Answered (decision 115):** the same
    cadence and calling hours as every other Lead, from attempt 1. When its
    attempts run out it goes on the manager's "finished, no contact" list like
    any other. *Alternative:* a shorter cadence of its own, which is a second
    set of settings.
26. **The 30 days.** **Answered (decision 116):** one number on Contact Center settings,
    from 1 to 180 days, starting at 30, changed by the people who change those
    settings today (Owner and Leadership). A Lead is released once that many
    full days have passed since its cancellation was recorded, whatever the
    time of day.
27. **Is the salesperson warned before a Lead is taken?** *Recommended:* yes.
    "To rebook" shows the days left, the bell warns them 5 days before, and
    tells them again when it is released. No email. *Alternative:* told only
    when it is released.
28. **Who may take a Lead back, and for how long** (rule 12)? *Recommended:*
    only the salesperson a Lead was released from by the timer, while it is
    still with the Contact Center at the same Location and they still have
    Sales Consultant access there. There is no time limit: the homeowner may
    call months later. A Lead a manager sent back cannot be taken back that
    way, because a manager chose to take it. *Alternative:* a limit, for
    example 90 days after release.

Still open with Sales Leadership and not needed for 6b: which salesperson an
issued Lead counts for after a move or re-issue; the real cancellation reasons;
offer validity; the Pending ownership period.

## Known limits of this plan

- The Calendar is a list, not a calendar. It does not show free time.
- Nothing checks the salesperson's personal calendar (rule 5).
- Appointment length is chosen for each booking; there is no settings page for
  the default yet.
- A booked appointment has no outcome. That comes with the after-action
  report.

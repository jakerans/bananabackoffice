# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-10 · **Branch:** `checkpoint-slice-6a-appointment-booking`

**State:** slice 6, step 6a is built and waits for Jacob's review and his word
to merge. It is on top of the plan branch (`checkpoint-slice-6-appointments-plan`,
also not merged), so merging this branch brings the plan, decisions 90 to 96 and
the build together. `main` and staging have none of it.

Jacob approved the plan and all seven of its recommendations on 2026-10-10
("Agreed to all"); they are decisions 90 to 96. Steps 6b and 6c are not started.

## Step

Slice 6, step 6a: book an appointment and issue the Lead. Plan:
[../slice-6-appointments-plan.md](../slice-6-appointments-plan.md), "Step 6a" and
"Step 6a as built". Rules: [../slice-5-appointment-rulebook.md](../slice-5-appointment-rulebook.md),
rules 1 to 5 and 10. Decisions 90 to 96 in [../decisions.md](../decisions.md).

Objective: staff can book an appointment for a Qualified Lead with a
salesperson, which hands the Lead to Sales, and a manager can undo a booking
that was made in error.

## What changed

- **Booking.** From a new "Appointment" card on the Lead page. It needs a
  Qualified Lead at a Location, linked to a customer with a property. The
  dialog asks for the salesperson, the day, the time and the length (90
  minutes unless changed), in the Location's own time.
- **Saving issues the Lead,** all in one step or not at all: assigned to the
  salesperson (taking it from whoever had it), status "Appointment booked", out
  of the call queue, the issue recorded for good, an entry in the customer's
  Activity, and a notice to the salesperson.
- **No double booking.** A salesperson cannot be given a time that overlaps
  another of their Backoffice appointments. Back-to-back is allowed.
- **Booked in error.** A manager, with a note. The appointment is kept and
  marked; the Lead goes back exactly as it was (status, the person it was with,
  and its place in the call queue); the issue no longer counts.
- **While an appointment is upcoming** the Lead cannot be moved to another
  Location, returned to central or disqualified.
- **Telling the salesperson.** A bell in the header of every page, with a count
  and a list. And an email with the time and a link, and nothing about the
  customer.
- **Sales > Calendar** is now a real page: a plain list of upcoming
  appointments by day.
- **New in the database:** `appointments`, `lead_issues`, `notifications`, and
  two columns on `leads`. Four migrations.
- **New permissions:** `appointments.book` (Owner, Leadership, Location
  Manager, Call Center Agent) and `appointments.manage` (Owner, Leadership,
  Location Manager). A Sales Consultant holds neither and may book only a Lead
  already assigned to them, for themselves.
- **New audit events:** `lead.appointment_booked` and
  `lead.appointment_booked_in_error`.
- **Documents:** the plan, decisions 90 to 96, the permission model, the
  navigation map, the roadmap, the security notes, the README and the slice 5
  rulebook's note about the call queue.
- **No** new package, no paid service, nothing sent to a customer.

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,123 tests (2,122 passed, 1 skipped, 10,685 assertions) in 297 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. The first full run had five failures, all in existing tests that pin lists this step was approved to change; they are described below. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. The MySQL job is the one that shows the four migrations apply there and that two bookings at the same moment are decided correctly on MySQL. |
| Browser | **Not looked at in a browser.** Steps for Jacob are below. Each browser action by the implementation agent needs an approval click from him, so it was left. |
| Merge, staging | **Not done.** |

New tests, 94 of them, in `tests/Feature/Appointments/`:

| File | What it shows |
| --- | --- |
| `BookAppointmentTest.php` (48) | Everything rule 3 lists happens together; the Lead is taken from whoever had it; it leaves the queue and a hold on it ends; each thing a Lead can be missing; who may book, as a matrix of eleven people; a Sales Consultant only for themselves; the salesperson must have access there; the Location's own time; eleven cases of what may and may not be booked; six cases of overlap, including back-to-back; the notice and the email carry no customer details; a failed email does not undo the booking; a changed Lead is refused; a failure part-way saves nothing. |
| `BookedInErrorTest.php` (23) | The Lead goes back exactly as it was, including into the queue at the attempt it had reached; the issue is voided; who may do it, as a matrix; the note is required; it cannot be done twice; the Lead can be booked again; a Lead with an upcoming appointment cannot be moved, returned or disqualified; a deactivated salesperson leaves the appointment on record. |
| `AppointmentScreensTest.php` (22) | The card in each state and for each role; refusals shown at their fields; only people the page offered can be booked; the Calendar's visibility rules and its timezones; the bell's count, list, "New" marker, that it is per person, and that an Owner viewing as someone does not use up their notices. |
| `BookingConcurrencyTest.php` (1) | Real separate processes: two people book the same salesperson into overlapping times at the same moment, eight times. Each time exactly one is saved and the other is refused with the words a person would see. |

Existing tests that changed, none weakened or removed:

- `tests/Unit/Enums/RoleTest.php` pins each role's exact permissions. The two
  new permissions are added to the four roles decision 90 names, and a new
  test pins exactly which roles hold each.
- `tests/Feature/Queue/LeaveCadenceTest.php` pins the list of Lead statuses, to
  show the call cadence adds none. The list now includes "appointment_booked",
  with a comment that it is slice 6's.
- The navigation test that used Calendar as its example of an unbuilt item now
  uses Commissions.
- A shared test helper that finds "the page" in a response now looks inside the
  page area, because the header holds a component of its own (the bell).

## Found while building

- **A time comparison bug, caught by the overlap tests before anything was
  committed.** Times typed in the Location's time were being compared with
  stored UTC times without being converted, so no overlap was ever found.
  Fixed; every comparison now converts first.
- **On MySQL a plain read inside the booking could have missed an appointment
  saved a moment earlier.** The overlap check is a locking read for that
  reason. The real-process test is what proves it on MySQL.

## Architectural decisions made while building

Listed in the plan under "Step 6a as built". The ones Jacob may want to change:

- **The salesperson is told even when they booked their own Lead.**
- **Sales > Calendar shows all of a person's Locations at once,** not the one
  chosen in the header.
- **A Lead whose appointment time has passed stays "Appointment booked"** and
  the Lead page offers it no lifecycle button. What happened at the
  appointment belongs to the after-action report, which is not built.
- **The Appointment card is a component of its own** inside the Lead page, so
  the Lead page, already very large, did not grow.

## Deviations from the approved plan

- The plan said "a notice inside the app" without saying when it is marked
  seen. As built: opening the list marks them seen.
- `tests/Support/queue-process.php` gained operations for the booking race.
  Test support only.

## Known limitations

- **A booked Lead has few ways out in 6a.** Before its appointment: only a
  manager's "booked in error". After it: it can be disqualified through the
  action, but the Lead page shows no button for it. Rescheduling and
  cancelling come in 6b.
- **If the salesperson loses access or is deactivated,** the appointment stays,
  the Lead becomes unassigned and the card says it needs a salesperson. The
  only fix in 6a is "booked in error" and booking again.
- **The Calendar is a list.** It shows no free time, and stops at 200
  appointments with a line saying so.
- **The email is sent while the person waits,** as invitation emails are.
- **Nothing checks the salesperson's own calendar** (rule 5).
- **The bell does not refresh by itself.** Its count is right when a page
  loads.
- Local database only: the fictitious staff, Locations and Leads left from the
  earlier browser reviews.

## Decisions needed from Jacob

1. **Review 6a** (steps below) and say whether to merge it.
2. **Any of the four choices under "Architectural decisions" to change?**
   Recommendation: keep all four for now.
3. **Before 6b can be planned,** the plan's decisions 8 to 11: what a Lead
   shows after it goes back to Contact Center; where it restarts in the call
   cadence; a cancellation that does not come from the customer; and whether
   rebooking after a cancellation changes the issued count.

## Browser review for Jacob

On the local site (`https://backoffice.test`, already on this branch and
migrated), signed in as the fictitious Owner. Use fictitious Leads only.

1. **A Lead that is not ready.** Open any Lead that is not Qualified. The
   "Appointment" card, under "Who has it", says no appointment can be booked
   yet and lists what is missing. There is no Book appointment button.
2. **Get a Lead ready.** Take a Lead at Northside through Start working, link
   a customer with a property, assign it, and Qualify it. The card now shows
   **Book appointment**.
3. **Book.** Press it. The salesperson list holds only people with Sales
   Consultant access at that Location. The dialog names the Location's
   timezone. Pick tomorrow at 3:00 PM and save. The card shows the day, "3:00
   PM to 4:30 PM" with the timezone, the salesperson and who booked it. The
   Lead's status reads **Appointment booked** and it is assigned to the
   salesperson.
4. **Double booking.** Get a second Lead ready and book the same salesperson
   tomorrow at 3:30 PM. It is refused at the time field with "… already has an
   appointment from 3:00 PM to 4:30 PM … then", and nothing is saved. 4:30 PM
   works.
5. **The bell.** View Backoffice as that salesperson. The bell in the header
   shows a count. (Opening it while viewing as them lists the notices and does
   not mark them seen; signed in as themselves it would.)
6. **Calendar.** Still as the salesperson, open Sales > Calendar: their
   appointments, by day. As the Owner or a Location Manager it lists everyone's
   at their Locations, with the salesperson's name on each.
7. **Blocked while booked.** On the booked Lead, as the Owner, try Change
   location and Return to central: each shows "This lead has an upcoming
   appointment. Cancel or undo its appointment first." and opens nothing.
8. **Booked in error.** As a manager, press **Booked in error** on the card.
   It needs a note. After saving, the card shows it was booked in error, with
   who and why, the Lead is Qualified again with whoever had it before, and
   Book appointment is offered again. A Call Center Agent and the salesperson
   do not see this button.
9. **A Sales Consultant's own Lead.** View as a Sales Consultant: on a ready
   Lead assigned to them, Book appointment offers only themselves. On a ready
   Lead that is not theirs there is no button.
10. **The email.** With mail set to the log locally, the newest entry in
    `storage/logs/laravel.log` is the salesperson's email: a time, a link, and
    no customer name, phone or address.

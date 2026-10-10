# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-10 · **Branch:** `checkpoint-slice-6b-change-cancel-send-back`

**State:** slice 6, step 6b is built and waits for Jacob's review and his word
to merge. `main` and staging have none of it. Step 6a and the 6b plan are
already in `main`.

Jacob answered every question the 6b plan asked on 2026-10-10. His answers are
decisions 105 to 114 in [../decisions.md](../decisions.md), written on this
branch. Step 6c is not started.

## Step

Slice 6, step 6b: change, cancel and send back an appointment. Plan:
[../slice-6-appointments-plan.md](../slice-6-appointments-plan.md), "Step 6b"
and "Step 6b as built". Rules:
[../slice-5-appointment-rulebook.md](../slice-5-appointment-rulebook.md), rules
6, 7, 8 (first two points), 9 and 11.

Objective: once an appointment is booked, staff can change its time, a manager
can give it to another salesperson, a cancellation is recorded and the Lead can
be booked again, and a manager can send the Lead back to the call center, which
is told what it is calling.

## What changed

All on the Lead page's Appointment card unless it says otherwise.

- **Reschedule.** The salesperson or a manager gives an upcoming appointment a
  new time. The salesperson stays the same and the earlier times stay on the
  card.
- **Move to another salesperson.** A manager only. The appointment keeps its
  time, the Lead goes to the new salesperson, and both people stay in its
  history.
- **Cancel appointment.** Anyone who may book the Lead. It asks who cancelled
  (the customer, the salesperson or the company), a reason from the new
  Cancellation Reasons list, and a note. The Lead shows **Appointment
  cancelled**, stays with the salesperson and still counts as issued.
- **"Other" as the reason** needs a note and asks whether the Lead is
  disqualified. Answered yes, the Lead is disqualified in the same step.
- **Book again.** A cancelled Lead, or one whose appointment time has passed
  ("Book another appointment"), can be booked again. It does not count as a
  new issued Lead. With a different salesperson it takes a manager.
- **Send back to Contact Center.** A manager only, with a note and an answer to
  "should this lead ever have been booked?". The Lead shows Qualified, belongs
  to nobody, and starts the call cadence over in the ordinary queue. "No, it
  should never have been booked" stops it counting as issued.
- **A cancelled Lead can be moved to another Location, returned to central or
  disqualified** from the Lead page's existing buttons. A move or a return
  sends it back to the Contact Center the same way. A manager is asked the
  same counting question; anyone else is told it still counts.
- **The call center is told** when a Lead came back from Sales: a notice on
  the Lead page and on the Call Queue card, saying whether its appointment was
  cancelled, by whom and why, and what the manager wrote.
- **Sales > Calendar** has a second list, "To rebook".
- **Lead & Sales Lists** has a seventh list, Cancellation Reasons, starting
  with Jacob's four.
- **The salesperson is told** by the bell when someone else reschedules,
  moves or cancels their appointment or their Lead goes back, and by email for
  a new time or a newly given appointment. Nothing carries customer details.
- **New in the database:** `appointment_changes`,
  `appointment_cancellation_reasons`, four columns on `appointments` and one
  on `leads`. Three existing columns that keep a copy of a Lead's status are
  made wider. Five migrations.
- **No new permission, no role change, no new package, nothing sent to a
  customer.**
- **New audit events:** `lead.appointment_rescheduled`,
  `lead.appointment_moved`, `lead.appointment_cancelled`,
  `lead.appointment_rebooked`, `lead.returned_from_sales`.

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,237 tests (2,236 passed, 1 skipped, 11,395 assertions) in 455 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. An earlier full run ended without printing any test results while the local web server was down; it was run again from the start and that is the result given here. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. The MySQL job is the one that shows the five migrations apply there and that the races below are decided correctly on MySQL. **Its first run on this branch failed** on the column length described under "Found while building"; the fix was pushed after it. |
| Browser | **Done on 2026-10-10 by the implementation agent: every step passed**, after two faults it found were fixed. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests, in `tests/Feature/Appointments/`:

| File | What it shows |
| --- | --- |
| `RescheduleAndMoveTest.php` (27) | A reschedule keeps the salesperson, the issue and the old time; who may reschedule, as a matrix of seven people; it is checked like a booking; it may overlap its own old time and nothing else; the salesperson is told only when someone else did it, with no customer details. A move hands over the appointment and the Lead and keeps both people; only a manager; the new person must have access, be someone else and be free; a manager gives an appointment a salesperson again after its own lost access; a failure part-way saves nothing. |
| `CancelAndRebookTest.php` (33) | The starting reasons; the cancelled record with who, why and the note; the Lead stays and still counts; all three "who cancelled"; the time is freed; who may record one, as a matrix of seven people; seven things a cancellation refuses; "Other" answered no and yes; only a manager can take the issue away while disqualifying. Booking again with the same salesperson writes no issue; a different salesperson needs a manager; a Lead with no salesperson waits for a manager; another appointment after one has passed; "booked in error" on a rebooking. |
| `ReturnFromSalesTest.php` (27) | Send back: Qualified, nobody, in the queue from attempt 1 as a new cycle, appointment cancelled, issue kept or voided by the answer; only a manager; what it refuses; a Lead with no cadence. The call queue hands a returned Lead out after Leads nobody has called. What the call center is told in each of three cases. Moving, returning and disqualifying a Lead that is with Sales, with and without "never booked", and refused for someone who is not a manager with nothing moved. |
| `AppointmentChangeScreensTest.php` (24) | Which buttons each of three people is offered; each opener refuses people who may not use it; every dialog's refusals at their fields; only people the page offered can be chosen; an agent's sent "never booked" is not believed; the notice on the Call Queue card; the counting question on the Lead page; the Calendar's "To rebook" list and who sees it; the new list on Lead & Sales Lists. |
| `BookingConcurrencyTest.php` (+1) | Real separate processes: one person books a time while another reschedules a different appointment of the same salesperson into it, four times. Each time exactly one is saved. |

Existing tests that changed, none weakened or removed:

- `BookAppointmentTest.php`: a Lead that already has an upcoming appointment is
  still refused, now with "Reschedule that one instead."
- `LeaveCadenceTest.php` pins the list of Lead statuses; it now includes
  "appointment_cancelled".
- `ListsTest.php`: the new list is added to the two sets of lists it checks.
- Two helpers used by several appointment test files moved into the shared
  test file.

## Found while building

- **The Appointment card's buttons stopped working after a booking until the
  page was reloaded.** Found by the browser review, not by the automated
  tests. The first way of making the Lead page refresh the card rebuilt the
  card, and the rebuilt card did not respond. Replaced: the Lead page now
  tells the card when the Lead has changed and the card reads it again. Tests
  were added for it.
- **An answered question still showed its earlier refusal** in the Cancel
  dialog. Found by the browser review. A field's refusal is now cleared when
  the field is changed.
- **The new status did not fit three columns on MySQL.** Found by the GitHub
  MySQL job on the first push, which failed; SQLite does not check a column's
  length, so every local test had passed. `lead_status_changes.from_status`
  and `to_status` and `contact_attempts.lead_status` held twenty characters
  and "appointment_cancelled" is twenty-one. A fifth migration widens them to
  forty, and a test now stores the status in each.
- **The local web server (Herd) had stopped** part-way through the work. It
  was started again for the browser review. Nothing in the application caused
  it as far as could be seen.

## Architectural decisions made while building

Listed in the plan under "Step 6b as built". The ones Jacob may want to change:

- **The counting question defaults to "still counts"** when a Lead that is with
  Sales is moved, returned or disqualified from the Lead page. Only the Send
  back dialog makes the manager choose.
- **A Lead that came back and is booked again is a new issued Lead.** If its
  first issue still counts it then has two. Decision 99 covers only a Lead
  still with its salesperson. Which salesperson each issue counts for is still
  open with Sales Leadership.
- **Booking a Lead that is with Sales with a different salesperson** tells the
  new salesperson and not the earlier one.
- **"Booked in error" is still offered on an appointment whose time has
  passed,** as in 6a.
- **"Other" is stored last in the list.** Jacob listed it first. It can be
  reordered on Lead & Sales Lists.

## Deviations from the approved plan

- One audit event, `lead.returned_from_sales`, where the plan named
  `lead.sent_back_to_contact_center`. It covers a Lead sent back, moved or
  returned to central, and says which.
- Decision 114 (telling the call center) was added by Jacob during the build
  and is built.
- `tests/Support/queue-process.php` gained a reschedule operation. Test
  support only.

## Known limitations

- **A salesperson who cannot make an appointment has no way inside Backoffice
  to ask a manager to move it** (decision 111).
- **Nothing happens after 30 days.** A cancelled Lead waits with its
  salesperson until someone books it or a manager sends it back. The release
  and the Cancelled Appointments button are step 6c.
- **Backoffice does not record whether a passed appointment was held or
  missed.** That is the after-action report.
- **A disqualified Lead's card says "Qualify this lead first."** True, and
  odd beside a cancelled appointment. Unchanged from 6a.
- **The Calendar is still a list,** and "To rebook" does not show how many of
  the 30 days are left.
- **The emails are sent while the person waits,** as before.
- Local database only: fictitious "Change Review" Leads, a fictitious second
  Sales Consultant ("Riley Rep (Sample)") at one Location, and their
  appointments.

## Decisions needed from Jacob

1. **Review 6b** and say whether to merge it.
2. **Any of the five choices under "Architectural decisions" to change?**
   Recommendation: keep all five for now.
3. **Before 6c can be planned:** how "beside new Leads" should look for the
   Cancelled Appointments button (decision 98), and where a released Lead
   starts in the cadence. These will be asked with the 6c plan.

## Browser review (done 2026-10-10)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff and Leads, in a real browser driven from the command
line. **Every step passed and the browser reported no page errors.** The first
run found the two faults under "Found while building"; the results below are
from the run after they were fixed.

| Step | Result |
| --- | --- |
| 1. What an upcoming appointment offers | Pass. A manager: Reschedule, Move to another salesperson, Cancel appointment, Booked in error, Send back to Contact Center. The salesperson: Reschedule and Cancel appointment. A call center agent: Cancel appointment only. |
| 2. Reschedule | Pass. The dialog starts on the time the appointment has. After saving: "The appointment has its new time."; the card shows the new time and, under "Earlier times", "Was Wed, Oct 14, 2026, 10:00 AM to 11:30 AM PDT with Sam Sales." with who changed it and when. |
| 3. Reschedule into a taken time | Pass. Refused at the time field: "Sam Sales already has an appointment from 1:00 PM to 2:30 PM PDT then. Choose another time." |
| 4. Move | Pass. The dialog offers the other Sales Consultant and not the one who has it; saving with nobody chosen is refused. After saving, the card and "Who has it" show the new salesperson, and "Earlier times" shows the move with its note. |
| 5. What the salespeople were told | Pass. The new salesperson's bell: "An appointment on Wed, Oct 14, 2026 at 11:00 AM PDT was given to you." The earlier one's: "Your appointment on ... was given to someone else.", and earlier "An appointment of yours was moved to ...". |
| 6. An agent records a cancellation | Pass. Saving with nothing chosen is refused ("Say who cancelled the appointment."). "No longer needed" does not ask about disqualifying. After saving: "The appointment is cancelled. The lead stays with Sam Sales to be booked again."; the card shows Cancelled, by the customer, the reason, the note and who recorded it; the status reads "Appointment cancelled"; the only button is "Book again". The agent's Book again dialog offers only that salesperson and says why. |
| 7. Calendar, To rebook | Pass. The salesperson sees the cancelled Lead with the day it was cancelled and the reason. |
| 8. A manager books it again | Pass. The dialog offers both salespeople and starts on the one it is with. The card then says "Booked again while the lead was with Sales. It does not count as a new issued lead." and lists the cancelled appointment under "Earlier appointments". |
| 9. Cancel with "Other" | Pass. Choosing Other asks "Is this lead disqualified?". No note is refused; no answer is refused. Answering yes asks why it is disqualified and, for a manager, whether it should ever have been booked. After saving: "The appointment is cancelled and the lead is disqualified."; the status reads Disqualified with nobody assigned. |
| 10. Send back | Pass. With the appointment upcoming the dialog says to use Move to another salesperson if someone else should take it. No answer and no note are each refused. After saving: Qualified, nobody assigned, the notice "Cancelled appointment: this lead came back from Sales" with the appointment, who sent it back and the note; the page's call queue panel shows it in the queue with 0 attempts made, cycle 2. |
| 11. A cancelled Lead moved to another Location | Pass. The Change location dialog says the Lead is with Sales and will go back to the Contact Center, and asks the counting question. After saving: at the new Location, Qualified, nobody assigned, with the notice and its cancellation reason. |
| 12. An appointment whose time has passed | Pass. The card says its time has passed and offers "Book another appointment"; Reschedule, Move and Cancel are not offered. The salesperson books another for themselves, and the passed one is listed under "Earlier appointments" as Passed. |
| 13. Cancellation Reasons | Pass. The list shows Holding off project, Went with someone else, No longer needed, and Other marked "Note required" and "Asks if the lead is disqualified". |
| 14. The Call Queue card | Pass. An agent pressing Next lead was handed the Lead from step 10: "Attempt 1 of 12", with the notice "Cancelled appointment: this lead came back from Sales", its appointment, who sent it back and the note. For this one step the other Leads in the local queue were made not yet due for the length of the check and then put back, so that Lead would be the one handed out. |

Not covered in the browser: the two new emails were not read in the mail
catcher (the automated tests check their text holds a time and a link and no
customer details); and "Return to central" for a cancelled Lead, which shares
its code with step 11 and is covered by the automated tests.

# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-12 · **Branch:** `checkpoint-slice-7a-run-or-no-show`

**State:** slice 7, step 7a is built and waits for Jacob's review and his word
to merge. `main` and staging have none of it. This branch sits on top of the
slice 7 plan branch (`checkpoint-slice-7-plan`, also not merged), so merging it
brings the plan, decisions 124 and 125, and the build together.

Jacob approved slice 7 in four steps on 2026-10-12 and agreed to all eleven
recommendations (decision 125). Steps 7b, 7c and 7d are not started.

## Step

Slice 7, step 7a: say what happened to an appointment once its time has come,
run or no-show. Plan:
[../slice-7-appointment-results-plan.md](../slice-7-appointment-results-plan.md),
"Step 7a" and "Step 7a as built". From the plan:
[../product-architecture.md](../product-architecture.md), 5.6, with decisions
121, 123, 124 and 125.

Objective: when an appointment's time comes, its salesperson or a manager
says whether it was run or the customer was a no-show, and nothing is left
unanswered without someone seeing it.

## What changed

- **On the Lead's Appointment card,** from the appointment's start time: "The
  time of the appointment on ... has come. What happened?" with two buttons,
  **Appointment was run** and **No-show**, each with an optional note. For the
  salesperson whose appointment it is, or a manager.
- **Run:** the card says so, with who and when. The Lead shows a new status,
  **Appointment run**, and stays with its salesperson. The card offers "Book
  another appointment" for a return visit, which adds no issued count.
- **No-show:** treated as a cancellation by the customer. The Lead shows
  Appointment cancelled, stays with its salesperson to be booked again, and
  after 14 days goes to the Cancelled Appointments queue, where the card the
  agent sees says "No-show". It still counts as issued.
- **An appointment has no result of its own.** Sold, Pending and Lost will
  belong to proposals, in steps 7b and 7c (decision 124).
- **A manager can correct an answer,** with a note: run to no-show, or back.
- **Sales > Calendar** has a new list at the top, **To close out**:
  appointments whose time has come with no answer yet, oldest first. A no-show
  then appears under "To rebook".
- **The customer page** shows Run or No-show against each appointment.
- **No database change:** two new appointment states and one new Lead status
  use columns that already exist. **No new permission, no new package, nothing
  sent to a customer.**
- **New audit events:** `lead.appointment_run`, `lead.appointment_no_show`,
  `lead.appointment_close_out_corrected`.

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,314 tests (2,313 passed, 1 skipped, 11,860 assertions) in 348 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| Browser | **Done on 2026-10-12 by the implementation agent: every step passed** and the browser reported no page errors. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests, `tests/Feature/Appointments/CloseOutAppointmentTest.php` (26): what
run records and what the Lead then shows; that an appointment keeps no result;
what can follow a run appointment; a no-show treated as a cancellation, booked
again with no new count, and released to the Cancelled Appointments queue
after 14 days with the notice saying no-show; who may answer, as a matrix of
six people; not before the start time and from the minute it starts; only run
or no-show, and only once; a cancelled appointment cannot be answered; a
changed Lead is refused; an earlier appointment answered after a later one was
booked; corrections, who may make them, the note, and a Lead that has moved
on; the card, the Calendar's list, the customer page; and the timer's repair.

Existing tests that changed, none weakened or removed:

- `AppointmentChangeScreensTest.php`: once an appointment's time has passed the
  card now asks what happened, where it used to say only that the time had
  passed.
- `AppointmentScreensTest.php`: a passed appointment is still left out of the
  upcoming list, and is now expected under "To close out".
- `LeaveCadenceTest.php` pins the list of Lead statuses; it now includes
  "appointment_run".

## Found while building

Nothing went wrong this time. Before pushing, each new stored name was checked
against the length of the column that holds it (the step 6b fault); all fit,
and no column was added.

## Architectural decisions made while building

Listed in the plan under "Step 7a as built". The ones Jacob may want to change:

- **A no-show Lead reads "Appointment cancelled"** in lists, like any Lead
  waiting to be booked again. Its appointment and the call center's notice say
  No-show. The alternative is a Lead status of its own, which every list and
  filter would then carry.
- **For the length of the appointment both sets of buttons are offered:** it
  can be marked run or no-show from its start time, and can still be cancelled
  or rescheduled until its end time.
- **An appointment whose Lead left Sales before anyone answered can no longer
  be answered.** It stays "Time has passed".
- **"Booked in error" is no longer offered once an appointment is run or a
  no-show.** A manager uses Send back, which asks whether it should ever have
  been booked.

## Deviations from the approved plan

None.

## Known limitations

- **Nothing makes anyone answer.** "To close out" shows what is waiting; no
  reminder is sent and nothing is blocked. The plan's "operationally
  incomplete until report is submitted" is shown, not enforced.
- **A run appointment leads nowhere yet.** Proposals and their results are
  steps 7b and 7c; until then a run Lead just stays "Appointment run".
- **No figures yet.** Demo Rate and the rest are step 7d.
- **The salesperson is not told when a manager answers or corrects** their
  appointment.
- Local database only: two fictitious "Close Out Review" Leads and their
  appointments.

## Decisions needed from Jacob

1. **Review 7a** and say whether to merge it.
2. **Any of the four choices under "Architectural decisions" to change?**
   Recommendation: keep all four.
3. Nothing is needed to start step 7b: its questions (4 and 5) were answered
   in decision 125. It starts when Jacob says so.

## The plan check for this step

Decision 122 asks for this after each slice; step 7a is one step of slice 7,
so this is short.

- **From the plan:** 5.6, the first thing the after-action report records.
- **In the plan and not built in this step:** everything else in 5.6, which is
  steps 7b and 7c; and the voice note, which decision 125 left out.
- **Built and not in the plan:** correcting an answer. It is in the slice 7
  plan Jacob approved.

## Browser review (done 2026-10-12)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff and Leads, in a real browser driven from the command
line. **Every step passed and the browser reported no page errors.** Two
fictitious Leads had appointments whose time had come.

| Step | Result |
| --- | --- |
| 1. The salesperson's Calendar | Pass. "To close out" listed both appointments, oldest first, each with its day and time, customer and property. |
| 2. Run | Pass. The Lead's card said "The time of the appointment on Sat, Oct 10 at 7:00 AM PDT has come. What happened?" with both buttons. "Appointment was run" opened "Mark this appointment as run?". After saving with a note: "Marked as run."; the status read "Appointment run"; the card showed "Appointment was run", who recorded it, when, and the note; "Book another appointment" was offered and opened its dialog without reloading the page. The salesperson was not offered the correction. |
| 3. No-show | Pass. "No-show" opened "Mark this appointment a no-show?". After saving: "Marked as a no-show. The lead stays with its salesperson to be booked again."; the status read "Appointment cancelled"; the card showed "No-show" and offered "Book again". In the Calendar it had left "To close out" and was under "To rebook" as "No-show" with 14 days. |
| 4. A manager corrects it | Pass. No note was refused ("Say why this is being corrected."). With a note: "Corrected."; the card showed "Appointment was run" with the manager's note, and the status read "Appointment run". |
| 5. The customer page | Pass. The appointment was listed as "Run". |

Not covered in the browser: the 14-day release of a no-show Lead and the notice
on the Call Queue card, which the automated tests cover and which use the
screens reviewed in step 6c.

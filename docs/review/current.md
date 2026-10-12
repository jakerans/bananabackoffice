# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-12 · **Branch:** `checkpoint-slice-7a-run-or-no-show`

**State:** slice 7, step 7a is built, and so is what Jacob asked to be added
to it after the first handoff (decision 126). It waits for his review and his
word to merge. `main` and staging have none of it. This branch sits on top of
the slice 7 plan branch (`checkpoint-slice-7-plan`, also not merged), so
merging it brings the plan, decisions 124 to 126, and the build together.

Steps 7b, 7c and 7d are approved in order (decision 125) and not started.

## Step

Slice 7, step 7a: say what happened to an appointment once its time has come,
run or no-show. Plan:
[../slice-7-appointment-results-plan.md](../slice-7-appointment-results-plan.md),
"Step 7a", "Step 7a as built" and "Added to step 7a on 2026-10-12". From the
plan: [../product-architecture.md](../product-architecture.md), 5.6, with
decisions 121 and 123 to 126.

## What changed since the first handoff (decision 126)

Jacob's reply: a salesperson cannot go on to another appointment until the
last one is closed out; a prompt, and an email 15 minutes afterwards; the
salesperson is told when a manager corrects or touches their appointment.

- **Close out first.** Once an appointment's end time has passed and nobody
  has marked it run or no-show, its Lead cannot be booked again, sent back to
  the Contact Center, moved, returned to central or disqualified. Each says
  "Say what happened at this lead's last appointment first: run or no-show."
  The card stops offering "Book another appointment" and "Send back" until it
  is answered.
- **In order.** A salesperson cannot mark a later appointment of theirs while
  an earlier one that is over has no answer: "Close out your earlier
  appointment first: the one on ...". A manager answering for them can.
- **A prompt on every page.** A salesperson with an appointment that is over
  and unanswered sees a bar under the header: "You have 1 appointment to close
  out. Say whether it was run or was a no-show before anything else. Close out
  now". The link opens the oldest one's Lead. The bar goes the moment the last
  one is answered.
- **A reminder 15 minutes after the appointment ends.** A notice in the bell
  and an email, once for each appointment. Neither says anything about the
  customer.
- **The salesperson is told** in the bell when a manager marks their
  appointment run or no-show, corrects the answer, or marks the appointment
  "booked in error".
- **One database change:** a new column on appointments that records when the
  reminder went, so it is sent once. **No new permission, no new package,
  nothing sent to a customer.**

The step as first built (the two buttons, Run and No-show, the correction,
"To close out" in the Calendar, the customer page) is unchanged and is
described in the plan under "Step 7a as built".

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,334 tests (2,333 passed, 1 skipped, 11,981 assertions) in 351 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| Browser | **Done on 2026-10-12 by the implementation agent: every step passed** and the browser reported no page errors. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests in `tests/Feature/Appointments/CloseOutAppointmentTest.php` (20
more, 46 in the file): each of the five things refused while an appointment is
over and unanswered, and allowed again once it is answered; not yet overdue
while the appointment is still going on; answering in order, and a manager not
held to it; the reminder at 15 minutes and not at 14, once only, in the app
and by email; the whole email read for the customer's name and phone; no
reminder for an appointment already answered, undone, or whose salesperson was
deactivated; the three notices to the salesperson, and none for their own
answer; the prompt on a page, for its owner only, with its count and its link,
and gone once answered; the Lead page saying why instead of opening a dialog.

Existing tests that changed because the rule changed. Each now tests the new
rule; none was removed:

- `CancelAndRebookTest.php`: "another can be booked after an appointment has
  passed" now expects a refusal first, and the booking once it is marked run.
- `AppointmentChangeScreensTest.php`: the card of a passed appointment no
  longer offers "Book another appointment" or "Send back" until answered.
- `ReturnFromSalesTest.php` (three tests): a Lead is sent back after its
  appointment was marked run, where it used to be sent back unanswered.
- `BookedInErrorTest.php`: "a finished appointment no longer blocks the lead"
  now expects it to block until answered.
- `CloseOutAppointmentTest.php`: "an earlier appointment answered after a
  later one was booked" now builds that state directly, as a record from
  before this rule.
- `CustomerAppointmentsTest.php`: the upcoming appointment is on another Lead
  of the same customer.
- `QueueTickTest.php`: the timer's result has one more count, `reminded`.

## Architectural decisions made while building

Listed in decision 126 under "Read by the implementation agent". The ones
Jacob may want to change:

- **"Can't start another appointment"** is built as the two blocks above.
  Backoffice has no step where a salesperson starts an appointment. The
  salesperson can still open their next appointment's Lead and see where to
  go; hiding that was judged to do more harm than good.
- **The block begins at the appointment's end time,** not its start.
- **The reminder is sent once.** After it, the prompt and the "To close out"
  list keep it in view.
- **A manager is not prompted or reminded** about other people's appointments.
- **The reminder uses the timer that already runs every minute,** so it goes
  15 to 16 minutes after the end time.

Unchanged from the first handoff: a no-show Lead reads "Appointment
cancelled"; "booked in error" is not offered once an appointment is answered.

## Deviations from the approved plan

None from decision 126. It changes decision 113, and that is recorded there.

## Known limitations

- **If the timer stops, no reminder is sent.** The block and the prompt do not
  depend on it. The Call Queue page already warns managers when the timer has
  not run lately.
- **A salesperson who was deactivated is not reminded.** Their unanswered
  appointment still blocks the Lead until a manager answers it.
- **When this reaches staging,** every fictitious appointment there that is
  already over and unanswered gets its one reminder on the first tick.
- **A run appointment leads nowhere yet.** Proposals and their results are
  steps 7b and 7c.
- **No figures yet.** Step 7d.
- Local database only: the two fictitious review Leads' appointments are now
  answered.

## Decisions needed from Jacob

1. **Review 7a** and say whether to merge it.
2. **Is "can't start another appointment" built the way he meant it?** See
   the first point under "Architectural decisions". Recommendation: keep it.
3. Nothing is needed to start step 7b. It starts when Jacob says so.

## The plan check for this step

- **From the plan:** 5.6, the first thing the after-action report records, and
  "Appointment remains operationally incomplete until report is submitted",
  which decision 126 now gives a meaning.
- **In the plan and not built in this step:** the rest of 5.6 (steps 7b and
  7c); the voice note, which decision 125 left out.
- **Built and not in the plan before today:** the block, the prompt, the
  reminder and the notices. Jacob decided them (126) and they are now written
  into 5.6.

## Browser review (done 2026-10-12)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff and Leads, in a real browser driven from the command
line. **Every step passed and the browser reported no page errors.** The
fictitious salesperson had two appointments that were over and unanswered,
and the timer had been run once.

| Step | Result |
| --- | --- |
| 1. The prompt and the reminder | Pass. On the dashboard and on the Calendar: "You have 2 appointments to close out. Say whether it was run or was a no-show before anything else. Close out now", linking to the older one's Lead. The bell held one new notice for each: "Your appointment on Fri, Oct 9, 2026 at 9:00 AM PDT is over. Say whether it was run or was a no-show. Nothing else can be done with the lead until you do." |
| 2. The later one first | Pass. Its card asked what happened and offered no "Book another appointment" and no reschedule. Marking it run was refused in the dialog: "Close out your earlier appointment first: the one on Fri, Oct 9 at 9:00 AM PDT." |
| 3. A manager and the unanswered Lead | Pass. The card offered neither "Book another appointment" nor "Send back". Move, Return to central and Disqualify each opened no dialog and said "Say what happened at this lead's last appointment first: run or no-show." |
| 4. The manager answers for the salesperson | Pass. "Marked as run." and "Send back" was offered again. The salesperson's prompt then read "You have 1 appointment to close out." and linked to the other Lead, and their bell had "A manager marked your appointment on Fri, Oct 9, 2026 at 9:00 AM PDT as run." |
| 5. The salesperson answers the last one | Pass. Marked a no-show from the card; the prompt disappeared without reloading the page, and "Book again" was offered. |

The steps of the first review (the two buttons, the correction, the Calendar
list, the customer page) passed on 2026-10-12 and those screens are unchanged
apart from what step 2 and 3 above show.

Not covered in the browser: the email itself, which the automated tests read
in full; and the 14-day release of a no-show Lead, reviewed in step 6c.

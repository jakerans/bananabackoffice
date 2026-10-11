# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-10 · **Branch:** `checkpoint-slice-6c-release-and-return`

**State:** slice 6, step 6c is built and waits for Jacob's review and his word
to merge. `main` and staging have none of it. Steps 6a and 6b are in `main`.
This branch sits on top of the 6c plan branch, so merging it brings the plan,
decisions 115 to 120 and the build together.

Jacob answered every question the 6c plan asked on 2026-10-10 (decisions 115 to
120), after the queue as it works today and two of the questions were explained
to him. With 6c, slice 6 is complete as planned.

## Step

Slice 6, step 6c: release and return. Plan:
[../slice-6-appointments-plan.md](../slice-6-appointments-plan.md), "Step 6c"
and "Step 6c as built". Rules:
[../slice-5-appointment-rulebook.md](../slice-5-appointment-rulebook.md), rule
8 (its last three points) and rule 12.

Objective: a cancelled Lead that its salesperson does not book again goes back
to the call center by itself; the call center works those Leads from a queue
of their own; and the salesperson can take one back when the customer comes
back to them.

## What changed

- **Release after 14 days.** A Lead whose appointment was cancelled stays with
  its salesperson for 14 days. If it is not booked again, the timer takes it
  back to the call center: Qualified, with nobody, marked as back from Sales,
  still counted as issued.
- **The number of days is a setting** on Contact Center settings, from 1 to
  180, changed by Owner and Leadership.
- **The salesperson is warned.** Sales > Calendar shows the days left on each
  cancelled Lead. The bell warns them 5 days before a Lead goes, and tells them
  when it has gone. No email.
- **A second call queue, Cancelled Appointments,** with its own button and
  count beside "Next lead". It holds every Lead that came back with its last
  appointment cancelled. "Next lead" never hands one out. The card says it is
  a cancelled booking, who cancelled and why.
- **This changes one thing 6b built:** a Lead a manager sends back with a
  cancelled appointment now goes into that queue, not the ordinary one.
- **Booking a Lead from that queue** is an ordinary booking and a new issued
  Lead, with its history kept.
- **Take back.** The salesperson a Lead was released from sees "Take this lead
  back" on the Lead and a "Released from you" list in the Calendar. With a
  short note the Lead is theirs again, to book, with no new issued count. The
  14 days start again.
- **New in the database:** one column on `lead_cadences`, three on `leads`, one
  on `contact_center_settings`. Three migrations.
- **No new permission, no role change, no new package, nothing sent to a
  customer.**
- **New audit events:** `lead.taken_back_by_sales` and
  `contact_center.release_days_changed`. A release is recorded as
  `lead.returned_from_sales`, saying it was released, with nobody named.

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed on the final code:** 2,281 tests (2,280 passed, 1 skipped, 11,669 assertions) in 426 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. The MySQL job is the one that shows the three migrations apply there and that the two-button race is decided correctly on MySQL. |
| Browser | **Done on 2026-10-10 by the implementation agent: every step passed** and the browser reported no page errors. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests:

| File | What it shows |
| --- | --- |
| `Appointments/ReleaseAndTakeBackTest.php` (25) | The waiting period starts at 14 days. A Lead is released at exactly that many days and not a minute before, once. What a released Lead looks like, including its issue, its queue and that nobody is recorded as having done it. A Lead booked again in time is never released. Who may change the number, its limits, and that a change applies to Leads already waiting. The warning is sent once, five days before, and the release tells them again; a short period has no warning. Each button hands out only its own queue, newest return first; one held Lead per person across both; the counts are kept apart. Booking from the queue is a new issue. Take back: theirs again with no new count, an agent's hold ended with the reason, the 14 days starting again; who may not; the note; and three ways a Lead stops being theirs to take, plus a Lead a manager sent back. |
| `Appointments/ReleaseScreensTest.php` (15) | The second button and both counts on the Call Queue; the manager summary; who is offered Take back and that the opener refuses everyone else; taking back from the card; the days left and the released list in the Calendar, and who sees them; the setting on its page with six refused values. |
| `Queue/QueueConcurrencyTest.php` (+1) | Real separate processes: three people press each of the two buttons at the same moment for two Leads in each queue. Each queue hands out only its own, every Lead has one holder, nobody holds two. |

Existing tests that changed, none weakened or removed:

- `QueueTickTest.php` pins what the timer reports. Its eight expectations now
  include the two new figures, "warned" and "released", both zero there.
- `ReturnFromSalesTest.php`: the test that a sent-back Lead is handed out
  after never-called Leads now uses a Lead whose appointment had passed, which
  is still the ordinary queue's; a new test shows a sent-back Lead with a
  cancelled appointment waits behind the other button (decision 117).
- `AppointmentChangeScreensTest.php`: the Call Queue card test presses the new
  button and checks that "Next lead" does not hand the Lead out.
- `ContactCenterSettingsTest.php`: the new save is added to the list of saves
  refused once a person loses access.

## Found while building

- Nothing was found by the GitHub jobs or the browser this time. Before
  pushing, every new stored name was checked against the length of the column
  that holds it, which is what the MySQL job caught in step 6b.
- **In the browser a number outside 1 to 180 is stopped by the browser itself**
  before it is sent, as the hold length already is. The server refuses it too;
  the automated tests show that.

## Architectural decisions made while building

Listed in the plan under "Step 6c as built". The ones Jacob may want to change:

- **The 14 days are counted from when the Lead became "Appointment
  cancelled".** They start again when a salesperson takes a Lead back.
- **Changing the number applies to Leads already waiting.** Lowering it can
  release Leads at once.
- **A Lead sent back as "should never have been booked", with a cancelled
  appointment, still goes into Cancelled Appointments.** The queue follows the
  appointment; the manager's note is what the agent reads.
- **Leads already in the ordinary queue stay there** when this is deployed.
  Staging has none that came back from Sales.
- **A manager sees every released Lead at their Locations,** with whose it
  was, and cannot take one back for a salesperson.

## Deviations from the approved plan

- The plan stored who a Lead was released from and when, and that is built. It
  did not mention the third column, when the salesperson was last warned,
  which the warning needs so it is sent once.
- The plan said 30 days throughout. Decision 119 made it 14.

## Known limitations

- **If the timer stops, nothing is released.** The Call Queue page already
  warns managers when the timer has not run lately.
- **A Lead is released at any time of day,** whenever its 14 days are up.
- **The bell lists a person's ten latest notices.** A warning can be pushed
  off the list by later notices; the Calendar always shows the days left.
- **"Released from you" has no end.** A Lead stays on it until it is booked,
  taken back or disqualified.
- **The manager summary lists held and finished Leads for both queues
  together.**
- Local database only: fictitious "Release Review" Leads and their cancelled
  appointments, two of them made to look two weeks old.

## Decisions needed from Jacob

1. **Review 6c** and say whether to merge it.
2. **Any of the five choices under "Architectural decisions" to change?**
   Recommendation: keep all five for now.
3. **What comes next.** Slice 6 is complete with this step. The roadmap's next
   work needs its own plan and approval.

## Browser review (done 2026-10-10)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff and Leads, in a real browser driven from the command
line. **Every step passed and the browser reported no page errors.** Four
fictitious Leads were cancelled and made to look as if that happened 16, 15,
10 and 0 days ago, and the timer was run once: it released the first two and
warned about the third.

| Step | Result |
| --- | --- |
| 1. The setting | Pass. Contact Center settings shows "Cancelled appointments" with 14 days and says the salesperson is warned 5 days before. 181 was stopped by the browser before it was sent. Saving 14 again: "Nothing was different, so nothing was saved." |
| 2. The salesperson's Calendar | Pass. "To rebook" shows the 10-day-old Lead with "Goes back to the Contact Center in 4 days unless it is booked again." and the fresh one with 14 days. "Released from you" lists the two released Leads. The bell shows "A cancelled lead of yours was not booked again in time and went back to the Contact Center. You can take it back from the lead." for each. |
| 3. Take back | Pass. The released Lead shows Qualified, nobody assigned, the notice "Cancelled appointment: this lead came back from Sales" with "Not booked again within 14 days of the cancellation.", and "Take this lead back". No note is refused. With a note: "This lead is yours again. Book its appointment when you have a time."; the status reads Appointment cancelled, it is assigned to the salesperson, the card offers "Book again", and the button is gone. In the Calendar it is back under "To rebook" with 14 days, and no longer under "Released from you". |
| 4. The Call Queue | Pass. An agent sees "14 leads due now" with Next lead, and under it "1 cancelled appointment due now" with Next cancelled appointment. Pressing the second button gave the other released Lead, "Attempt 1 of 12", with the notice naming its appointment, who cancelled, the reason and why it came back. The line under the card then read "14 other leads due now · 0 cancelled appointments due now". |
| 5. A manager's Calendar | Pass. "Released to the Contact Center" lists the released Lead with the salesperson it was released from. |

Not covered in the browser: the warning notice itself was not seen in the bell,
because the bell lists a person's ten latest notices and the test data had made
more than ten (the timer reported one warning sent, and the automated tests
check its wording); and booking a Lead from the Cancelled Appointments queue,
which is the ordinary booking reviewed in step 6a.

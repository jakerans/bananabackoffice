# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-slice-9b-rehash`

**State:** slice 9, step 9b is built and waits for Jacob's review and his word
to merge. `main` and staging do not have it.

Step 9a is merged, on 2026-10-13 with Jacob's word ("Merge and start.",
decision 138). Both GitHub jobs passed on the merge and staging deployed it
with every migration run.

## Step

Slice 9, step 9b: Rehash. Plan:
[../slice-9-pending-rehash-plan.md](../slice-9-pending-rehash-plan.md), "Step
9b" and "Step 9b as built". From the plan:
[../product-architecture.md](../product-architecture.md), 4.2, 5.5 and 20;
rulebook rules 8 and 12; decision 137, answers 4 to 7.

Objective: a bid nobody closed stops sitting with its salesperson for good. It
goes to the call center with what was pitched, and comes back as a return
visit, a lost bid, or a Lead its salesperson took back.

## What changed

**The release.**

- The day after a Lead's last day with its salesperson, the timer sends it to
  the Contact Center. The last day is the day its last pending proposal
  expires.
- **A Lead run with nothing presented** has the same seven days and then goes
  too (answer 5).
- The Lead shows Qualified with nobody assigned, still counts as issued, and
  keeps its proposals as they were.
- A Lead with a return visit booked, sold or lost is never released.
- The salesperson is told in the bell: "A lead of yours was not sold, lost or
  booked again in time and went to the Contact Center for rehash. You can take
  it back from the lead."

**The Rehash queue.**

- The Call Queue has a third button, "Next rehash", with its own count: "3
  rehashes due now". The manager summary counts them too.
- The card says what the setter is calling about before they dial: "Rehash:
  this lead has a bid that is still pending", then "Whole exterior, $8,450.
  Still pending. Main objection: Wants to think it over. Expired yesterday,
  Mon, Oct 12." with the salesperson's note. For a Lead with nothing
  presented it gives the reason the salesperson wrote.
- Same cadence and calling hours as every other Lead.

**What a rehash call ends in.**

- **A return visit,** booked as any appointment is, with any eligible
  salesperson. It is a new Lead Issued for whoever gets it (answer 7), and the
  proposals go with the Lead.
- **"The customer said no: mark lead lost",** on the Lead's Proposals card,
  with a Sales loss reason and the objection (answer 6). The bid is lost with
  that reason, the Lead leaves the queue, and the salesperson it came from is
  told.

**Taking it back.** The salesperson it was released from sees "Take this lead
back" on the Lead, as for a cancelled one. It comes back as Appointment run
with its proposals, and they have seven days with it from that day. Sales >
Calendar lists it under "Released from you".

**No database change, no new permission, no new package, nothing sent to a
customer.**

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,719 tests (2,718 passed, 1 skipped) in about 390 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| Browser | **Done on 2026-10-13 by the implementation agent: every step passed** and the browser reported no page errors. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests, 29, in `tests/Feature/Proposals/`:

- `RehashReleaseTest.php` (21): the release at half past midnight in the
  Location's time and not an hour before, once, with what the Lead, its
  proposal, its issue, the salesperson and the call center each have
  afterwards; five Leads that are never released; a Lead with nothing
  presented, warned and then released; a Lead whose only proposal was marked
  lost; a Lead never released sooner than seven days after it came back; a
  Lead whose salesperson is gone; only the Rehash button handing it out;
  booked again as a new issue for another salesperson, presented again and
  sold as their sale; taken back as Appointment run with its time starting
  again; marked lost by the call center and everything that follows; who may,
  as a matrix of five people; two refusals; and a manager's undo putting it
  back in the queue.
- `RehashScreensTest.php` (8): the third button, its count and the card; the
  other buttons not handing a rehash out; the manager summary; marking lost
  from the Lead page as the call center; what a manager and the salespeople
  are and are not offered in Rehash; taking back from the appointment card;
  the line on a Lead with nothing presented; Sales > Calendar.

Existing tests that changed, none weakened: `ProposalValidityTest.php` (step
9a) now expects the new words of the warning, expects a Lead with nothing
pending to have a last day too, and no longer says that nothing is released.
One of its cases, "nobody is warned about a Lead whose proposal was added in
error", was removed because answer 5 makes the opposite true; the new test of
a Lead with nothing presented covers it.

## Architectural decisions made while building

Listed in the plan under "Step 9b as built". The ones Jacob may want to
change:

- **A Lead is never released sooner than seven days after it last became
  Appointment run.** Otherwise a Lead whose proposal had expired would be
  released the minute a return visit was marked run, or the minute its
  salesperson took it back. The plan already says "Offer Valid Until" and
  "Salesperson Ownership Until" are separate (5.5).
- **With nothing presented, the seven days count from when the appointment
  was marked run.**
- **Marking a Lead's only proposal lost, without marking the Lead lost,**
  gives seven days from then; then it goes to Rehash as "nothing is pending".
- **The call center marks it lost from the Lead page,** not from the queue
  card. The card has "Open the full lead".
- **Whoever may book that Lead an appointment may mark it lost from Rehash:**
  a Call Center Agent and a manager there. The salesperson it was released
  from takes it back first.
- **In Rehash, nobody gives a proposal a result.** A sale comes from a return
  visit or from the salesperson taking it back.
- **A Lead a manager sends back by hand still goes to the ordinary queue.**
  Only the timer sends a Lead to Rehash.
- **A manager's undo of "lost" puts the Lead back in the Rehash queue,**
  because nobody had it.

## Deviations from the approved plan

None from decision 137. One addition to step 9a's wording: the warning now
reads "A lead of yours stays with you for 2 more days. It then goes to the
Contact Center for rehash, ...", so that it is also true for a Lead with
nothing presented.

## Known limitations

- **A Lead with nothing presented is not on "Pending bids"** on Sales >
  Calendar. Its last day is on the Lead and in the bell warning.
- **Nobody can hold one Lead back** except by moving a proposal's day, adding
  a proposal or booking a visit.
- **Rehash has no script or pricing rules** for the setter. The card shows
  what was pitched; what may be offered is not decided here.
- **A Lead that uses up its rehash calls** stays with the call center under
  "cadence finished", like any other Lead.
- **Two setters pressing "Next rehash" at the same instant** go through the
  same claim as the other two buttons, which is tested with real processes.
  There is no separate two-process test for this button.
- **When this is installed, any Lead already past its last day is released
  within a minute.** Proposals pending when step 9a was installed got seven
  days from that day; a Lead with nothing presented did not. Staging has no
  Lead showing Appointment run, so nothing moves there.
- Local database only: three fictitious Leads were made for the review and
  left as released, lost and taken back. The local web server had stopped
  during the review and was started again with `herd restart`.

## Decisions needed from Jacob

1. **Review step 9b** and say whether to merge.
2. **Then step 9c,** the Pending figures on Sales > Performance: Pending Rate,
   return visit booked at the appointment, and Rehash conversion, as decision
   137 approved them. It starts on his word after this review.

## Browser review of step 9b (done 2026-10-13)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff, customers and Leads, in a real browser driven from the
command line. **Every step passed and the browser reported no page errors.**
Three Leads were made whose time ran out the day before.

| Step | Result |
| --- | --- |
| 1. The release | Pass. Before the timer, one Lead read "Everything pending on this lead has expired. It is due to go to the Contact Center for rehash" and another "Nothing is pending on this lead. It is due to go ...". After it ran, the Lead showed Qualified, assigned to nobody, with "Rehash: this lead has a bid that is still pending" and what was pitched. |
| 2. The call center | Pass. The Call Queue showed "3 rehashes due now" and "Next rehash". The card read "Rehash: this lead has a bid that is still pending", "Deck and rail, $2,400. Still pending. Main objection: Wants to think it over. Expired yesterday, Mon, Oct 12." and left "2 rehashes due now". |
| 3. The customer said no | Pass. On the Lead the call center was offered "The customer said no: mark lead lost" and not "Sold". Saved with no reason it was refused: "Choose why it was lost." With one: "Lead marked lost. It has left the rehash queue.", the Lead showed Lost, and the queue went down to 2. |
| 4. The salesperson | Pass. Their bell had the three releases and "A lead that went from you to rehash was marked lost by the Contact Center." Sales > Calendar listed the two still out under "Released from you". On one, "Take this lead back" with a note brought it back as Appointment run, with "After Tue, Oct 20 this lead goes to the Contact Center for rehash" and its proposal still shown as expired. |
| 5. Nothing presented | Pass. That Lead's notice read "Rehash: this lead had an appointment and nothing was presented" with the reason the salesperson had given. |
| 6. A phone's width | Pass. The Rehash block of the Call Queue fitted the screen. |

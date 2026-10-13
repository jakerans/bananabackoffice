# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-slice-7c-results`

**State:** slice 7, step 7c is built and waits for Jacob's review and his word
to merge. `main` and staging have steps 7a and 7b and none of 7c. Jacob said
"Go ahead and start." on 2026-10-12.

Step 7d (the first Sales figures) is approved in order (decision 125) and not
started.

## Step

Slice 7, step 7c: a proposal's result, correcting a proposal, a Lead that is
Sold or Lost, and the after-action report. Plan:
[../slice-7-appointment-results-plan.md](../slice-7-appointment-results-plan.md),
"Step 7c" and "Step 7c as built". From the plan:
[../product-architecture.md](../product-architecture.md), 5.6, with decisions
124, 125 (answers 6 to 9) and 128.

Objective: after an appointment the salesperson says, in a minute or two from
a phone, what happened, what was pitched and what became of it; and every
piece of that can also be done or put right one at a time on the Lead.

## What changed

**The after-action report.** A page of its own, made for a phone, opened from
the Appointment card ("After-action report"), from each row of "To close out"
in the Calendar, and from the "Close out now" prompt. It asks only what the
answers so far need:

1. Run, or no-show. A no-show ends the report.
2. If run: was anything presented? "No" ends the report, and the appointment
   counts as run with nothing presented.
3. Each thing presented, with its price and what became of it: Sold (with the
   sold amount), Still pending (with the main objection), or Lost (with the
   reason and the main objection). On a return visit, the proposals still
   pending from before are listed to tick and answer.
4. Short notes.

It is saved whole or not at all, and a refusal is shown on the proposal it is
about. The two quick buttons, "Appointment was run" and "No-show", are still
there beside it.

**On the Proposals card, one piece at a time:**

- Each proposal shows Pending, "Sold for $...", "Lost: reason" or "Added in
  error".
- **Sold / Lost / Still pending** on a pending proposal, for the salesperson
  the Lead is with, or a manager.
- **Change** a pending proposal's name or price (decision 128).
- **Mark lead lost:** every proposal still pending becomes lost with the same
  reason and objection.
- **A manager only, each with a note:** "Correct result" (the proposal goes
  back to pending), "Added in error" (it stays on the record and counts for
  nothing), and "Undo: the lead is not lost".
- **The return-visit check:** "No return visit is booked for what is still
  pending." or "A return visit is booked for ...".

**The Lead:**

- Shows **Sold** when one of its proposals is sold, and **Lost** when it is
  marked lost. Both are finished: it cannot be booked, sent back, moved or
  disqualified, and it leaves the lists of open Leads. It stays on its
  salesperson's record.
- A manager's correction brings it back to Appointment run.

**Also:**

- **Objections** is a new list on Lead & Sales Lists. It starts with "Other
  (see note)", which needs a typed note.
- **The salesperson is told** in the bell when a manager gives a result,
  changes or marks a proposal, marks the Lead lost or undoes that.
- **Database:** new columns on proposals and Leads, and one new table
  (`objections`). **No new permission, no new package, nothing sent to a
  customer.**
- **Eight new audit events;** all but "proposal changed" are in the customer's
  Activity.

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,492 tests (2,491 passed, 1 skipped) in about 390 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| Browser | **Done on 2026-10-13 by the implementation agent: every step passed** and the browser reported no page errors. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests, 107, in `tests/Feature/Proposals/`:

- `ProposalResultsTest.php`: sold, with the amount starting at the price or
  typed, and the Lead showing Sold; refused while an appointment is still
  booked; the other proposals left alone and still answerable; lost, with
  seven refusals; the competitor kept only when the reason asks; still
  pending, said again; a result given once; who may, as a matrix of six
  people; another Lead's proposal; a Lead that left Sales; a manager's
  correction and what it does to the Lead; changing a proposal and what is
  kept; "added in error" and that it counts for nothing; marking a Lead lost,
  with nothing presented, and each time it is refused; a finished Lead refusing
  to be booked, sent back, moved or disqualified; never released by the timer;
  undoing a lost Lead; and every notice to the salesperson.
- `AfterActionReportTest.php`: each path through the report; nine refusals,
  each checked to have saved nothing at all; another Lead's proposal; who may
  send it; and that it follows the rules of the buttons (not before the start,
  once, the oldest first).
- `ProposalResultScreensTest.php` and `AfterActionReportScreenTest.php`: what
  each person is offered; every opener refused to whoever may not; each dialog
  from the card; the report asking only what is needed; a refusal landing on
  the right proposal; an empty proposal dropped; a return visit; who may open
  the page; an answered appointment sent back to the Lead.

Existing tests that changed, none weakened:

- `LeaveCadenceTest.php` pins the list of Lead statuses; it now includes
  "sold" and "lost".
- `ListsTest.php` runs its checks for the new Objections list too.
- `CloseOutAppointmentTest.php`: the prompt's link is now expected to open the
  report.

## Found while building

- **A component's setting was named `errors`,** which is the name Laravel
  already uses on every page for the list of refusals, so refusals were saved
  but not shown. Two screen tests caught it before the browser did. Renamed.
- **The browser review showed one refusal twice** in the report, and a
  corrected proposal saying only "Recorded by" its manager. Both fixed.

## Architectural decisions made while building

Listed in the plan under "Step 7c as built", "Readings made while building".
The ones Jacob is most likely to want to look at:

- **A Sold or Lost Lead is finished** and stays assigned to its salesperson.
  Nothing more can be done with it except a manager's correction.
- **One lost proposal does not make the Lead lost.** Someone marks the Lead
  lost.
- **A sale is refused while the Lead still has an appointment booked.**
- **Still pending needs the main objection,** and until the real list exists
  the only one is "Other (see note)", which needs a typed note. This is the
  one place the report is slower than it should be: see "Decisions needed".
- **The sold day is the day it is recorded.**
- **The return-visit check is on the Lead, not in the report,** because a
  return visit cannot be booked before the appointment is answered (decision
  126).

## Deviations from the approved plan

- The plan put "whether a return visit is booked" in the report as item 4. It
  is on the Lead instead, for the reason above. The report says to book it
  from the Lead once saved.

## Known limitations

- **Sold does not create a Project,** so nothing reaches Production yet
  (rulebook rule 13; a later slice).
- **Pending has no clock.** A proposal can stay pending for good; Pending
  ownership and Rehash are a later slice.
- **No voice note** (decision 125).
- **"Added in error" cannot be undone.**
- **No figures yet.** Demo Rate, Close Rate, Sold Revenue and NSLI are step
  7d. Everything they count is now recorded.
- Local database only: three fictitious "Result Review" Leads and one extra
  objection, "Wants to think it over".

## Decisions needed from Jacob

1. **Review 7c** and say whether to merge it.
2. **A starting list of objections.** With only "Other (see note)", every
   Still pending and every Lost needs a typed note. Jacob, or anyone who is an
   Owner, can add objections on Lead & Sales Lists at any time. If Jacob gives
   a starting list, as he did for cancellation reasons, it can be seeded.
   Recommended until Sales Leadership answers: Price, Wants to think it over,
   Needs to talk to spouse or partner, Getting other bids, Timing, Financing,
   and Other (see note).
3. Nothing is needed to start step 7d. It starts when Jacob says so.

## The plan check for this step

- **From the plan (5.6):** Won / Pending / Lost; primary option pitched and
  amount; sold amount if Won; main objection if Pending; Lost Reason and
  Primary Objection if Lost; system check whether rehash booked; short notes.
  All built. "Appointment remains operationally incomplete until report is
  submitted" was built with decision 126.
- **In 5.6 and not built:** the voice note and its transcript (decision 125).
- **Built and not in the plan:** correcting and "added in error" (decision
  128); undoing a lost Lead (the slice 7 plan's "correct an answer already
  given").

## Browser review (done 2026-10-13)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff and Leads, in a real browser driven from the command
line. **Every step passed and the browser reported no page errors.** The
report was filled in at a phone's width.

| Step | Result |
| --- | --- |
| 1. The report | Pass. The prompt's "Close out now" opened the report for the oldest unanswered appointment. It first showed only "1. What happened?", with no save button. Run, then Yes, then saving with nothing filled in: "Say what was pitched." A proposal marked Still pending with no objection was refused at that field. With an objection chosen, a second proposal added and marked Sold for $1,750, and a note: the page went to the Lead with "Report saved. The lead now shows Sold."; the status read Sold; the card showed "Whole exterior, $8,450, Pending, Main objection: Wants to think it over" and "Deck and rail, $1,900, Sold for $1,750". |
| 2. Results from the card | Pass. The card said "No return visit is booked for what is still pending." Lost with no reason: "Choose why it was lost." Choosing "Chose Another Contractor" made the contractor field appear. Saved: "Lost: Chose Another Contractor", "Went with Brightside Painters.", "Main objection: Wants to think it over."; the Lead stayed "Appointment run". "Change" opened with the name and "8,450" filled in; saved as $8,900. |
| 3. The Lead is lost | Pass. An objection that needs a note was refused without one. Saved: "Lead marked lost."; status Lost; the card showed "Lead marked lost: Price" with the objection, the note and who recorded it, and the proposal as "Lost: Price". The salesperson was offered no Add proposal, no undo and no booking. |
| 4. A manager undoes it | Pass. No note was refused ("Say why this is being undone."). With a note: "The lead is no longer marked lost."; status "Appointment run"; the proposal Pending again with its buttons. |
| 5. Sell, correct, added in error | Pass. A manager marked a proposal sold: status Sold, "Sold for $8,900", no booking offered. "Correct result" with a note: "Corrected. The proposal is pending again." and the status went back to "Appointment run". "Added in error" with a note: the proposal showed "Added in error", who marked it, the note and "It counts for nothing." |
| 6. The salesperson is told | Pass. Their bell held "A manager changed the proposals or the result on a lead of yours." for each change. |
| 7. The Objections list | Pass. Lead & Sales Lists showed Objections with "Other (see note)" marked "Note required". |

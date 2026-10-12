# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-12 · **Branch:** `checkpoint-slice-7b-proposals`

**State:** slice 7, step 7b is built and waits for Jacob's review and his word
to merge. `main` and staging have step 7a (merged 2026-10-12 with "Merge it
and continue.") and none of 7b.

Steps 7c and 7d are approved in order (decision 125) and not started.

## Step

Slice 7, step 7b: proposals. Plan:
[../slice-7-appointment-results-plan.md](../slice-7-appointment-results-plan.md),
"Step 7b" and "Step 7b as built". From the plan:
[../product-architecture.md](../product-architecture.md), 5.3 and 5.6, with
decisions 121, 124 and 125.

Objective: once an appointment has been run, the salesperson records what was
pitched and for how much, so an appointment with a proposal can be told from
one with nothing presented. That is what Demo Rate will count.

## What changed

- **A new card on the Lead page, Proposals,** under the Appointment card. It
  shows nothing until the Lead has an appointment that was run, and appears
  the moment one is marked run.
- **Add proposal:** what was pitched ("Whole exterior"), the price in dollars,
  and which run appointment or appointments it was presented at. With one run
  appointment it is already chosen. For the salesperson the Lead is with, or a
  manager. It can be added on the day or later.
- **Each proposal shows** its name, its price, the appointment or appointments
  it was presented at, who added it and when.
- **A return visit:** "Presented at another appointment" ties a proposal to a
  second run appointment.
- **A run appointment with no proposal says so:** "The appointment on ... was
  run and has no proposal yet. Until one is added it counts as run with
  nothing presented."
- **Everyone who may see the Lead sees its proposals,** the Contact Center
  included, as the plan says (11.4). Only the salesperson and a manager add.
- **The salesperson is told** in the bell when a manager records a proposal at
  their appointment (decision 126).
- **Nothing about the Lead changes** when a proposal is added: same status,
  same person.
- **Two new tables** (`proposals`, `appointment_proposal`). **Two new audit
  events** (`lead.proposal_added`, `lead.proposal_presented_again`), both in
  the customer's Activity. **No new permission, no new package, nothing sent
  to a customer.**

Also in this branch: the record that step 7a is merged.

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| `composer test` on the Windows development machine (style, static analysis, every test) | **Passed:** 2,382 tests (2,381 passed, 1 skipped, 12,153 assertions) in 358 seconds. The skipped test is the device-time formatter, which cannot find Node on Windows. |
| GitHub "Checks and tests (SQLite)" and "Migrations and tests (MySQL 8.4)" | Reported with the branch; not recorded here. |
| Browser | **Done on 2026-10-12 by the implementation agent: every step passed** and the browser reported no page errors. Results are under "Browser review" below. |
| Merge, staging | **Not done.** |

New tests, 48 in `tests/Feature/Proposals/`:

- `ProposalsTest.php`: what adding a proposal records, and that the Lead is
  left as it was; the price as typed in six forms and kept in cents; nine
  things that are not a price, each refused with nothing saved; the name; that
  a proposal needs a run appointment of its own Lead (none, one still to come,
  a no-show, another Lead's, one that does not exist); who may add, as a matrix
  of six people; the salesperson refused once the Lead has left them and a
  manager still allowed; a changed Lead refused; a proposal cannot be deleted;
  several proposals for one appointment and several appointments for one
  proposal; a return visit recorded once only, and its refusals; an
  appointment counting as presented from the moment a proposal is added, a day
  late, and once however many proposals it has; the notice to the salesperson.
- `ProposalScreensTest.php`: the card hidden until an appointment is run and
  appearing without a reload; the Lead page holding it; adding from the card;
  each refusal at its own field; another Lead's appointment refused whatever
  the browser sends; an agent seeing proposals and refused the opener and the
  save; someone who may not see the Lead refused the card; the return visit
  from the card.

No existing test changed.

## Architectural decisions made while building

- **Money is kept in whole cents** (decision 127, made by the implementation
  agent; Jacob can change it). People type and see dollars.
- **Every proposal has a name, a price and at least one run appointment,** so
  there is no half-finished proposal and every saved one counts for Demo
  Rate.
- **Once a Lead has left its salesperson, only a manager can still add** what
  was pitched.
- **The salesperson is told when a manager records a proposal at their
  appointment.** Decision 126 said "touches their appointments"; this was read
  as covering it.

## Deviations from the approved plan

None.

## Known limitations

- **A proposal cannot be edited or removed.** A wrong name or price stays. The
  plan did not say how one is corrected. See "Decisions needed".
- **A proposal has no result yet.** Pending, Sold and Lost are step 7c.
- **Demo Rate is not shown anywhere yet.** The figures are step 7d. This step
  only makes it countable, and a test proves the count.
- **No proposal without a run appointment** (decision 125, answer 5).
- Local database only: two fictitious "Proposal Review" Leads with their
  appointments and proposals.

## Decisions needed from Jacob

1. **Review 7b** and say whether to merge it.
2. **How is a wrong proposal corrected?** Recommended, to be built with step
   7c: the salesperson or a manager can change the name and price while the
   proposal is still Pending, each change kept in the audit record; a proposal
   added by mistake is marked "added in error" by a manager with a note and
   then counts for nothing. Nothing is ever deleted.
3. Nothing else is needed to start step 7c: its questions (6 to 9) were
   answered in decision 125.

## The plan check for this step

- **From the plan:** 5.6's "primary option pitched + amount", and decision
  124's rule that only a proposal has a result.
- **In the plan and not built in this step:** the result of a proposal and the
  report form (7c); the figures (7d); the rest of the selling flow in 5.3
  (discovery, measurements, pricing, options, presentation, agreement,
  deposit), which is not in slice 7.
- **Built and not in the plan:** nothing.

## Browser review (done 2026-10-12)

Done by the implementation agent on the local site (`https://backoffice.test`)
with fictitious staff and Leads, in a real browser driven from the command
line. **Every step passed and the browser reported no page errors.**

| Step | Result |
| --- | --- |
| 1. The card appears | Pass. On a Lead whose appointment had no answer, no Proposals card. After the salesperson marked it run, the card appeared without a reload: "The appointment on Sun, Oct 11, 2026 at 10:30 AM PDT was run and has no proposal yet. Until one is added it counts as run with nothing presented." and "Add proposal". |
| 2. Add a proposal | Pass. The dialog opened with the one run appointment already chosen. No name: "Say what was pitched." A price in words: "Enter the price in dollars, like 8450 or 8,450.50." With "8,450.50": "Proposal added: Whole exterior, two coats, $8,450.50."; the card showed the name, the price, "Presented at: Sun, Oct 11, 2026 at 10:30 AM PDT" and "Added by Sam Sales"; the notice about no proposal was gone; the Lead still read "Appointment run". |
| 3. A return visit | Pass. A Lead with two run appointments and one proposal said the second had no proposal yet. "Presented at another appointment" opened a dialog naming the proposal and offering only the second appointment. After "Record it": "Recorded at that appointment too.", both appointments listed, the notice gone, the button no longer offered. |
| 4. The call center | Pass. An agent saw the proposal and its price and was not offered "Add proposal". |
| 5. A manager adds one | Pass. "Proposal added: Cabinets only, $3,200." The salesperson's bell then had "A manager recorded a proposal at your appointment on Sat, Oct 10, 2026 at 11:00 AM PDT." |
| 6. The customer's Activity | Pass. "Proposal added" twice and "Proposal presented again" once. |

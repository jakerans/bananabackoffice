# Slice 9 — Pending Ownership and Rehash: Proposed Plan

Status: **approved on 2026-10-13 (decision 137) in three steps, each stopping
for Jacob's review, with all nine questions answered as recommended. Step 9a
is built and waits for his review; see "Step 9a as built". Steps 9b and 9c
are not built.** It was written because Jacob said "yes merge and continue"
(decision 136) and this is what the plan has next.

It follows [product-architecture.md](product-architecture.md), sections 4.2,
5.1, 5.5, 5.6 and 20, the slice 5 rulebook
([slice-5-appointment-rulebook.md](slice-5-appointment-rulebook.md), rules 8,
12 and 14), decision 131, and builds on slices 6 and 7.

## Why this is next, from the plan

1. **The plan's own order.** Section 20 gives a customer's path: "Salesperson
   → issued lead → confirmation → appointment → Pending/Rehash/Won/Lost", and
   then "If Pending ownership expires: Sales → Contact Center Rehash". Won is
   built (slice 8). Lost is built (step 7c). Pending is built only as a word
   on a proposal.
2. **Today a Pending has no clock.** A Lead whose appointment was run and
   whose proposal is still pending stays with its salesperson for good. Nobody
   is reminded, nothing expires and nobody else can try. The same Lead with a
   cancelled appointment is released after 14 days (rule 8).
3. **It was blocked and no longer is.** Rule 14 was provisional at 30 days
   until Sales Leadership answered. They have: "Proposal validity defaults to
   seven days and a salesperson may change it. Sales ownership normally ends
   with that same expiry. On expiry, the Pending opportunity releases to
   Contact Center Rehash" (plan, 5.5; decision 131).
4. **What else could have been next, and why not first.** Pre-production
   (6.3, 6.4) is the next step for a sold job and can follow. The deposit and
   payments (10.4, 10.6) touch QuickBooks, whose integration check has not
   been done. This finishes what Sales already does and uses the call queue
   that is already built.

## What the plan already settles

| The plan says | Where |
| --- | --- |
| Pending is a commercial status. "Thinking it over" is an objection, not a status. | 5.5 |
| A proposal is valid for seven days by default, and a salesperson may change it. | 5.5, decision 131 |
| Sales ownership normally ends with that same expiry. Then it releases to Contact Center Rehash. | 5.5 |
| "Offer Valid Until" and "Salesperson Ownership Until" are separate concepts. | 5.5 |
| The salesperson it was released from can take it back with a short note: same history, no new issued count. | Rule 12, decision 81 |
| The after-action report has a "system check whether rehash booked". | 5.6, built in 7c |
| The figures to come: Pending Rate, % Pending with Rehash booked during the appointment, Rehash conversion. | 5.2 |

## What slice 9 is, and is not

Three reviewed steps. Each stops for Jacob's review.

| Step | Delivers | From |
| --- | --- | --- |
| 9a | **The clock.** Every pending proposal has a "valid until" day. The salesperson sees how long each has left, can change it, and is warned before a Lead is released. | Plan 5.5 |
| 9b | **Rehash.** When the time is up, the Lead goes to the Contact Center in a queue of its own, Rehash, with what was pitched on the card. The call center books a return visit. The salesperson can take it back. | Plan 4.2, 5.5 and 20; rules 8, 12 and 14 |
| 9c | **The Pending figures** on Sales > Performance: Pending Rate, return visit booked at the appointment, Rehash conversion. | Plan 5.2 |

Not in slice 9, each needing its own plan and approval:

- **Any message to a customer** about an offer that is about to expire. No
  message to a customer has been authorized in any slice.
- **Sales confirmation calls** and the selfie video (5.3).
- **The booking engine** (12) choosing the slot for a rehash. A person still
  picks the salesperson and the time, as in slice 6.
- **Broadcasts** to pending customers (4.5).
- **The morning briefing** ("Pending needing follow-up", "missing rehash").
- **Commission** and anything in Finance.

## Step 9a — The clock

### What a person sees

- **On a Lead's Proposals card,** each pending proposal shows "Valid until
  Tue, Oct 20 · 5 days left", or "Expired 2 days ago".
- **The salesperson, or a manager, can change the day,** from the proposal's
  "Change". See question 3 for how far.
- **Above the proposals,** one line says when the Lead leaves the salesperson:
  "This lead goes to the call center for rehash on Oct 21 unless a return
  visit is booked or it is sold or lost."
- **Sales > Calendar** gets a list beside "Cancelled leads to rebook":
  **"Pending bids"**, the salesperson's Leads with something pending, soonest
  to expire first, each with what was pitched, the price, the main objection
  and the days left. A manager sees everyone's at their Locations.
- **A warning in the bell** two days before a Lead is released: "A pending bid
  of yours goes to the call center in 2 days."
- **Settings > Contact Center** gets one more number beside the cancelled
  waiting period: "Days a proposal is valid", starting at 7.

Nothing is released in this step. The dates are shown and can be changed, so
they can be looked at before anything moves.

### What it stores

| Table | What |
| --- | --- |
| `proposals` | `valid_until`, a day. Filled in for every proposal that is pending when this is installed, counted from that day so that nothing is already expired. |
| `leads` | `pending_warned_at`, so the warning is sent once. |
| `contact_center_settings` | `proposal_valid_days`, 7. |

"Salesperson Ownership Until" is not stored. It is worked out from the
proposals (question 2), so the two cannot disagree by accident. It becomes a
stored date of its own the day a rule needs it to differ.

### Step 9a as built (2026-10-13)

Built on the branch `checkpoint-slice-9a-pending-clock`. **Not merged; it
waits for Jacob's review.** Built as planned above. What a reader of the code
should know:

- **The rules are in one place,** `App\Support\Proposals\ProposalValidity`:
  the day a proposal is valid until, the days left, the day a Lead's
  ownership ends, and reading a day a person chose. Every day is a day in the
  Lead's Location's own calendar, and a last day ends at midnight there.
- **A proposal gets its day when it is added** (`AddProposal`): the number of
  days in the settings after the latest appointment it was presented at.
  **Presented again** (`PresentProposalAgain`), a pending proposal gets that
  many days from the return visit, if that is later than the day it has.
- **The day is changed in the proposal's "Change" dialog** (`EditProposal`),
  while the proposal is pending. The salesperson may go 30 days ahead; a
  manager any day from today. What it was is in the audit record with the
  name and price.
- **The warning** is `App\Actions\Proposals\WarnOfPendingRelease`, run by the
  every-minute timer and counted with its other warnings. One bell notice
  when the last pending proposal has two days or fewer left, once for each
  last day: if the day is moved on, the salesperson is warned again when the
  new one is near. A Lead with a return visit booked, sold or lost is not
  warned.
- **"Pending bids" on Sales > Calendar** lists Leads that show Appointment
  run and have something pending, placed by the day their ownership ends.
- **The setting** is `proposal_valid_days` in the Contact Center settings,
  from 1 to 90, changed by whoever may change those settings.

Readings made while building, for Jacob to change if wrong (also in decision
137):

- **A proposal typed in late** is valid until today at the earliest.
- **A proposal made pending again by a correction,** or when a lost Lead is
  undone, starts its days again from that day.
- **Presenting again never shortens** a proposal's time.
- **A day cannot be moved to before today.**
- **A new number of days applies from then on;** a proposal already pending
  keeps its day.
- **The Lead stores the day it was warned about,** `pending_warned_for`, not
  when it was warned as the table above says, so that moving the day on
  brings a new warning by itself.
- **A Lead run with nothing presented** (answer 5) gets its clock in step 9b,
  with the release it belongs to.

## Step 9b — Rehash

### What a person sees

- **The timer releases a Lead** when its ownership is up: it has a pending
  proposal, no appointment booked, and every pending proposal has expired. It
  leaves the salesperson, shows Qualified, and joins a new call queue,
  **Rehash**, with its own button and count beside "Call queue" and
  "Cancelled appointments". The salesperson is told in the bell.
- **The card in the call queue** says it is a pending bid: what was pitched,
  for how much, the main objection, the salesperson, the day of the visit and
  any note. The script is different from a new Lead's, so the setter is told
  before they dial, as with a cancelled booking (decision 117).
- **The setter books a return visit** with any eligible salesperson, as a
  cancelled Lead is rebooked today (rule 8). The proposals go with the Lead,
  still pending, and get a new "valid until" when they are presented again.
- **If the customer says no,** the setter marks the Lead lost with a reason
  and the objection (question 6).
- **The salesperson it was released from can take it back** from the Lead with
  a short note (rule 12). It is theirs again as Appointment run, with its
  proposals, and the clock starts again. Sales > Calendar lists the Leads
  released from them, as it does for cancelled ones.
- **Same cadence and calling hours** as every other Lead.

### What it stores

| Table | What |
| --- | --- |
| `lead_cadences` | A third value for `queue`, `rehash`. |
| `leads` | Nothing new: `released_from_user_id` and `released_from_sales_at` already exist for rule 12. |

## Step 9c — The Pending figures

On Sales > Performance, for a month, a Location and a salesperson, counted for
each Lead as Close Rate is (decision 135):

| Figure | Counted as |
| --- | --- |
| Pending Rate | Of the Leads first demoed in the month, the ones that left that first demo with nothing sold and nothing lost. |
| Return visit booked | Of those, the ones with a return visit booked by the end of the day of the demo. The plan's "% Pending with Rehash booked during appointment". |
| Rehash conversion | Of the Leads released to Rehash in the month, the ones later sold. |

These are readings. Question 8 asks whether they are right before 9c is
built.

## Who may do what

No new permission.

| What | Who |
| --- | --- |
| See the dates and the "Pending bids" list | Anyone who may see the Lead; the list as Sales > Calendar is today. |
| Change a proposal's "valid until" | The salesperson the Lead is with, or a manager at its Location (`manageProposals`). |
| Change "Days a proposal is valid" | Whoever may change the Contact Center settings today. |
| Work the Rehash queue | Whoever may work the call queue today. |
| Take a released Lead back | Only the salesperson it was released from (rule 12). |

## Audit and Activity

Each is an audit event saved with its change: a proposal's validity changed;
the setting changed; a Lead released to Rehash; a Lead taken back. Released
and taken back appear in the customer's Activity, as they do for a cancelled
Lead.

## Tests

As slices 6 and 7 had: who may and may not, as a matrix of roles and
Locations; every refusal; the timer releasing at the right minute in the
Location's time and never twice; a Lead with a return visit booked, sold or
lost never released; two setters pulling from Rehash at the same instant (real
processes); the warning sent once; the figures against a worked month; and the
migrations on MySQL 8.4, with the new queue name checked against its column.

## Decisions needed

**Answered by Jacob on 2026-10-13 (decision 137): "I agree with all of that.
Go ahead."** All nine are as recommended. The questions are kept below as
they were asked.

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **When do the seven days start?** *Recommended:* the day the proposal was
   last presented, so a proposal presented again at a return visit gets
   seven more days. *Alternative:* the day it was first created, whatever
   happens after.
2. **A Lead with two pending proposals that expire on different days.**
   *Recommended:* the salesperson keeps the Lead until the last of them
   expires. One Lead has one owner, so it cannot be half released.
3. **How far can a salesperson move "valid until"?** The plan says they may
   change it. *Recommended:* up to 30 days from today on their own; a manager
   to any day. Otherwise a salesperson could keep every Pending for good by
   moving the date, and the clock would mean nothing. *Alternative:* no limit.
4. **Does a booked return visit keep the Lead with the salesperson past the
   expiry?** *Recommended:* yes. A Lead with an appointment booked is never
   released; if that visit is cancelled or is a no-show, the existing
   cancelled rules take over (14 days, rule 8).
5. **A Lead whose appointment was run with nothing presented.** It has no
   proposal, so nothing to expire, and today it stays with its salesperson for
   good. *Recommended:* the same number of days from the appointment, then it
   goes to Rehash too, marked "nothing was presented" with the reason the
   salesperson gave. *Alternative:* leave it out of this slice.
6. **What a setter does when the customer says no.** *Recommended:* the setter
   marks the Lead lost, with a Sales loss reason and the objection, so the
   reasons are counted with every other lost bid. *Alternative:* the setter
   disqualifies it, as with any other Lead in the queue, which would put lost
   bids under the Contact Center's reasons instead.
7. **Is a rehash booked by the call center a new Lead Issued?** Today a
   cancelled Lead that was released and is rebooked counts as issued again,
   for the salesperson who gets it (rule 8, "a re-issue with full history").
   *Recommended:* the same for Rehash, so one rule covers both. It means a
   rehash adds one to that salesperson's Leads Issued, which NSLI is divided
   by. *Alternative:* a rehash never adds a Lead Issued.
8. **The three figures in step 9c,** as the table above. *Recommended:*
   approve as written, or say which one means something else. 9a and 9b do not
   wait on this.
9. **The warning.** *Recommended:* one bell notice two days before release,
   no email. The cancelled warning is five days before a 14-day wait.

## Known limits of this plan

- **Nothing tells the customer** their offer is about to expire.
- **An expired proposal can still be sold.** "Valid until" is information for
  the salesperson and the clock for ownership; it does not lock the price.
- **A manager cannot stop one Lead from being released** except by moving its
  date (question 3) or booking a visit.
- **Rehash has no script or pricing rules of its own** for the setter. The
  card shows what was pitched; what may be offered is not decided here.

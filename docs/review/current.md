# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-13 · **Branch:** `checkpoint-slice-9-plan`

**State:** a plan waits for Jacob's approval. **Nothing in it is built.**

Close Rate as demo to close is merged, on 2026-10-13 with Jacob's word ("yes
merge and continue", decision 136). Both GitHub jobs passed on the merge and
staging deployed it. Nothing else waits for review.

## The plan

**Slice 9 — Pending ownership and Rehash:**
[../slice-9-pending-rehash-plan.md](../slice-9-pending-rehash-plan.md).

It is what the plan has next (product-architecture.md, 5.5 and 20; decision
131). Today a pending bid has no clock: it stays with its salesperson for
good, nobody is reminded, and nobody else can try. Sales Leadership has now
said what the rule is: a proposal is valid for seven days by default, Sales
ownership ends with it, and then it goes to Contact Center Rehash.

Three steps, each stopping for review:

| Step | Delivers |
| --- | --- |
| 9a | **The clock.** A "valid until" day on every pending proposal, the days left shown to the salesperson, a "Pending bids" list on Sales > Calendar, a warning before release. Nothing is released yet. |
| 9b | **Rehash.** When the time is up the Lead goes to the call center in a queue of its own, with what was pitched on the card. The call center books a return visit. The salesperson can take it back. |
| 9c | **The Pending figures** on Sales > Performance: Pending Rate, return visit booked at the appointment, Rehash conversion. |

No new permission, no new package, and no message to a customer.

## Decisions needed from Jacob

Each has a recommendation in the plan, under "Decisions needed". "Follow your
recommendations" is a complete answer.

1. **When the seven days start.** Recommended: the day the proposal was last
   presented.
2. **Two pending proposals that expire on different days.** Recommended: the
   salesperson keeps the Lead until the last one expires.
3. **How far a salesperson can move "valid until".** Recommended: up to 30
   days on their own; a manager to any day.
4. **A booked return visit keeps the Lead with the salesperson.**
   Recommended: yes.
5. **A Lead run with nothing presented** has no clock at all today.
   Recommended: the same number of days, then Rehash.
6. **When the customer tells the call center no.** Recommended: the setter
   marks the Lead lost with a Sales loss reason.
7. **Is a rehash booked by the call center a new Lead Issued?** Recommended:
   yes, as a cancelled Lead that is rebooked is today. It adds to the
   salesperson's Leads Issued, which NSLI is divided by.
8. **What the three figures in 9c mean.** Recommended: as the plan's table.
   9a and 9b do not wait on this.
9. **The warning.** Recommended: one bell notice two days before release.

To start, Jacob needs to approve the plan, or step 9a alone, and answer
questions 1 to 4. Questions 5 to 7 are needed before 9b, and 8 before 9c.

## What the plan says could come after

None of it approved:

1. **Pre-production** (6.3, 6.4): rough timeframe, colors and readiness.
2. **The deposit and payments** (10.4, 10.6), which touch QuickBooks.

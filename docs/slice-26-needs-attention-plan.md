# Slice 26 — The Exception Engine and Needs Attention: Plan

Status: **approved on 2026-10-26 (decision 185), with the recommendation on
all eight questions, to be built straight through 26a, 26b and 26c and then
reviewed. Being built.** Written as what the implementation agent
recommended from the plan check below.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 25:

1. **Every Center now opens on a working board** (slices 7, 23 and 25), and
   each board lists some of its own trouble: jobs at risk, late balances,
   overdue job costing.
2. **Nothing gathers that trouble and puts it in front of the person who
   owns it.** The plan's "Exception Engine first, AI interpretation second"
   (19) surfaces meaningful exceptions by severity (FYI, Needs Attention,
   Urgent), turns one with a known owner and next step into that person's
   task, and keeps the pressure inside Backoffice rather than in email. The
   plan names this engine among the foundations to build first (26), and
   success means "managers see exceptions early" (27).
3. **Company > Needs Attention** (18), the cross-functional view of it, is
   still Coming Soon.

What else could be next, and why not first: **Leadership Scorecards**
(17.3) need named owners and targets; the **subcontractor marketplace**
(7.4) and **Painter for a Day** (8) are large; **My Banana** (13) and the
**Inbox** (4.3) wait on providers.

## What slice 26 is, and is not

Three reviewed steps.

| Step | Delivers |
| --- | --- |
| 26a | **The exception engine.** A set of rules, each reading the records Backoffice already keeps, that every 15 minutes opens an exception for each thing that needs someone, with its owner, its severity, the record it is about and when it was first seen, and closes it by itself once the thing is fixed. |
| 26b | **Needs Attention.** Each person's own list, on their Home page and in the header; **Company > Needs Attention** for the Owner and Leadership; a manager's view of their Locations. Each item links to its record. Each can be marked useful, not useful, or dismissed, with an optional reason. |
| 26c | **The morning briefing.** On Home, a short summary for the person's role: a salesperson's pending proposals and confirmations; a Production Manager's late jobs, job costing due, balances to chase and Brand Ambassador items; a manager's exceptions and misses at their Locations. In Backoffice, not by email. |

Not in slice 26:

- **AI:** no AI reads, writes or decides anything (19: the engine first,
  AI second). Every item traces to a record.
- **Email and text messages:** Urgent items ring the bell in Backoffice;
  nothing is emailed or texted (19).
- **Trends and coaching insights** that need history (low Demo Rate,
  short-call patterns, slot utilization): later, once the engine runs.

## The first rules

| Exception | Owner | Severity |
| --- | --- | --- |
| A proposal pending past its validity with no rehash | Its salesperson | Needs Attention |
| A sold job whose handoff is not done after a day | Its salesperson | Needs Attention |
| Work running past its last day | The job's Production Manager | Urgent |
| Job costing not submitted 7 days after signoff | The job's Production Manager | Needs Attention |
| A final balance past due | The job's Production Manager (Collections after 30 days) | Needs Attention; Urgent with Collections |
| A job projected below its target margin before the close | The job's Production Manager | FYI |
| A Brand Ambassador item past its day | The job's Production Manager | Needs Attention |
| A warranty case with nobody to own it | The Location's managers | Urgent |
| A subcontractor's compliance item expiring within 30 days, or expired | The Location's Production Managers | Needs Attention; Urgent once expired |
| A credit, refund or write-off waiting more than 2 days | Those who may decide it | Needs Attention |
| Cash or check waiting for Finance's approval more than 2 days | Finance at the Location | Needs Attention |
| A pay period over and not finalized after 5 days | Finance and the Owner | Needs Attention |

## Decisions needed

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **The steps and their order.** *Recommended:* 26a, 26b, 26c.
2. **The first rules, owners and severities.** *Recommended:* the table
   above; more are added as they are asked for.
3. **Kept or worked out each time.** *Recommended:* kept, swept every 15
   minutes by the scheduler already running: so each has a first-seen time,
   a history and feedback, and closes by itself when fixed.
4. **Who sees what.** *Recommended:* each person their own; a manager every
   item at their Locations; the Owner and Leadership all of them on Company
   > Needs Attention. Never an item about a record the person may not open.
5. **Feedback.** *Recommended:* useful, not useful, or dismiss, with an
   optional reason. A dismissed item leaves that person's list until it
   clears and comes back; the feedback is kept for tuning the rules.
6. **Urgent items.** *Recommended:* the owner's bell rings once when it
   opens; nothing is emailed or texted.
7. **The briefing.** *Recommended:* on Home, every time it is opened,
   counting from that morning; no email.
8. **AI.** *Recommended:* none in this slice.

## Step 26a as built

- **The twelve rules** of the table above read the records Backoffice keeps
  (question 2). Two narrowings: a pending proposal past its validity counts
  only while its Lead is still with Sales; a subcontractor's compliance
  counts only while they are Active. A job with no Production Manager goes
  to the Location's managers.
- **Kept** (`attention_items`; question 3): each finding is an item for each
  of its owners, with its rule, what it is about, its Location, severity,
  words and link, when first and last seen. `backoffice:attention` sweeps
  every 15 minutes: it opens what is new, keeps what is still found up to
  date, and closes what is no longer found, because it is fixed. Found again
  later, it is a new item.
- **Urgent** items ring their owner's bell once, when they open (question
  6). Nothing is emailed or texted. No AI (question 8).

## Known limits of this plan

- **Fifteen minutes behind:** an item opens or closes at the next sweep.
- **Rules are in code:** a new rule, or a different threshold, is a change
  to Backoffice, not a setting yet.

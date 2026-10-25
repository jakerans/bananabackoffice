# Slice 22 — Warranty: Plan

Status: **approved on 2026-10-25 (decision 177), with the recommendation on
all eight questions, built straight through 22a, 22b and 22c, and merged on
2026-10-25 (decision 178). Slice 22 is complete.**
Written as what the implementation agent recommended from the plan check
below; what was built is under "as built".

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 21:

1. **A job now runs from first call to financial close, pay and the Brand
   Ambassador items** (slices 8 to 21). Closeout is complete.
2. **What happens after it is the next gap:** the plan's ownership chain
   ends "Completed → PM active ownership ends → … → warranty if needed"
   (20), and Production's locked navigation still has **Warranty** as
   Coming Soon (6).
3. **Its rules are mostly written** (16): a Warranty Case is linked to the
   original Project, has an owner (the original Production Manager if
   active, otherwise the Location's manager, who may reassign it), a
   description and photos, a scheduled date, a separately approved warranty
   budget, its actual cost and its completion. The work is done by whoever
   fits: the original subcontractor, another one, a W-2 painter, a warranty
   technician, the Production Manager or someone else. A subcontractor's
   payout is decided case by case and may be $0 for a corrective return.
4. **Its money is written too:** warranty cost is direct cost (10.1); it
   rolls into the original job's lifetime gross margin (16), and for the
   Production Bonus it counts in the month it is recorded, without reopening
   the job (6.1; decision 133).

What else could be next, and why not first: the **sales leaderboard** (5.8)
and the **Performance boards** (17) report on numbers that already exist;
**refunds, credits and write-offs** (10.5, 10.7) affect few jobs;
**My Banana**, **Referrals** and **Painter for a Day** are large and
customer-facing.

## What slice 22 is, and is not

Three reviewed steps.

| Step | Delivers |
| --- | --- |
| 22a | **The Warranty Case.** Opened from a job by anyone who can see it: what the customer reports, photos, and who reported it. It belongs to the job's Production Manager if they are still active there, otherwise to a Location manager, who can give it to another Production Manager. It is **open**, then **scheduled**, then **completed**, or **closed without work** with why. **Production > Warranty** lists the cases at the Location; the job shows its own. The owner is told. |
| 22b | **The visit and who does it.** A manager approves a **warranty budget**, with why ($0 is allowed), before the visit is scheduled. The owner schedules the day and who does it (the original subcontractor, another one, a W-2 painter, a warranty technician, a Production Manager, or someone else). The visit shows on Production > Schedule. Completing it records what was done, with photos. |
| 22c | **Warranty cost.** Costs are recorded on the case like job costs, with receipts, including a subcontractor's payout (which may be $0). The original job shows its **lifetime gross margin**: the final one less its warranty cost. Each warranty cost also counts against that job's Production Manager's Production Bonus, in the month it is recorded. |

Not in slice 22:

- **W-2 time clocks and Performance Pay** for warranty work: W-2
  Performance Pay is deliberately not defined yet (8.8; open question 14).
  A W-2 painter's warranty hours are recorded as a labour cost in dollars.
- **Customers opening a case themselves** through My Banana (13): not
  built yet. Staff open it from what the customer tells them.
- **Video:** photos and PDFs only, within the 20 MB upload limit.
- **A warranty period or automatic eligibility:** no rule exists; the owner
  decides, and a case not covered is closed without work, with why.

## Who may do what

| What | Who |
| --- | --- |
| Open a case on a job | Anyone who can see the job. |
| Give a case to another Production Manager | A manager at the Location. |
| Approve the warranty budget | A manager at the Location, with why. |
| Schedule, complete, close without work, record costs | The case's owner or a manager there. |

## Decisions needed

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **The steps and their order.** *Recommended:* 22a, 22b, 22c.
2. **Who opens a case.** *Recommended:* anyone who can see the job: its
   salesperson, its Production Manager, a manager, or Finance. The Contact
   Center opens one later, from the customer workspace.
3. **Who owns it.** *Recommended:* the job's Production Manager if they are
   still an active Production Manager at the Location; otherwise every
   manager there is told, and one of them gives it to a Production Manager
   or keeps it (16).
4. **The budget.** *Recommended:* a manager approves it, with why, before
   the visit can be scheduled; $0 is allowed. Costs above it are allowed
   and shown in red, as on the job budget.
5. **Where warranty cost counts.** *Recommended:* in the original job's
   lifetime gross margin, shown on the job and on Finance > Job Costing;
   and against that job's Production Manager's Production Bonus, in the
   month the cost is recorded (6.1). Sales commission does not change.
6. **The visit on the schedule.** *Recommended:* it shows on Production >
   Schedule on its day, with who does it, like a work package.
7. **A subcontractor's warranty calls.** *Recommended:* the case records
   which subcontractors did the original work, so their warranty-call rate
   (7.11, VIP eligibility) can be counted later; it is not shown yet.
8. **Telling the customer.** *Recommended:* nothing is sent to the
   customer; the owner calls them, as today.

## Step 22a as built

- **A warranty case** (`warranty_cases`) is opened from the job's new
  Warranty card by anyone who can see the job: what the customer reports,
  and who reported it and how. It keeps the subcontractors who did the
  original work (question 7).
- **Its owner** is the job's Production Manager while they are an active
  Production Manager at the Location; they are told. Otherwise every
  manager there is told, and a manager gives it to a Production Manager or
  keeps it, from the case's page.
- **Its page** (`/projects/{job}/warranty/{case}`) shows what was reported,
  the owner, the original subcontractors and its photos, as reported and
  after the work. Its owner or a manager closes it without work, with why,
  in the app's own dialog.
- **Production > Warranty** is live: the cases at the Location, open and
  scheduled first (nobody's, then the person's own), then those finished in
  the last 30 days.
- Opening, giving and closing a case are audited and shown in the job's
  activity. Nothing is sent to the customer.

## Step 22b as built

- **The warranty budget:** a manager at the Location approves it on the
  case, with why; $0 is allowed, as for a subcontractor correcting their own
  work. It can be changed while the case is open or scheduled. Who approved
  it and when is kept.
- **The visit:** once the budget is approved, the case's owner or a manager
  schedules the day (today or later) and who does it: the original
  subcontractor (one of those who did the work), another subcontractor who
  works at the Location, a W-2 painter, a warranty technician, a Production
  Manager, or someone else, by name. It can be moved while scheduled.
- **Production > Schedule** shows each warranty visit in its week, with the
  job, what was reported, who does it and the owner, linked to the case.
- **Completing** a scheduled case records what was done; photos after the
  work are added on the case's page. Each step is audited and shown in the
  job's activity.

## Step 22c as built

- **Warranty costs** (`warranty_costs`) are recorded on the case by its
  owner or a manager there who may also see payouts: materials, a
  subcontractor's payout (which alone may be $0), W-2 labour in dollars, or
  other, with who it was paid to, the day and its receipt. A wrong one is
  voided with why. Not on a case closed without work. Each is audited.
- **The case shows what it cost** against the approved budget, in red and
  marked "Over budget" when above it. Costs are finance-sensitive: those who
  cannot see payouts do not see them.
- **The lifetime gross margin:** the job's costing card says "Lifetime gross
  margin, after $X of warranty", from the final gross margin (or the
  projection before it) less its warranty cost. Finance > Job Costing lists
  the jobs with warranty cost recorded in the last 90 days, with their
  lifetime gross margin.
- **The Production Bonus:** each warranty cost counts below zero for the
  job's Production Manager in the month it is recorded; a void gives it back
  in the month it is voided. The job is never reopened. Sales commission
  does not change.

## Known limits of this plan

- **No warranty period** is checked; the owner decides each case.
- **A warranty case does not reopen the job:** its cost is counted in the
  month it is recorded, never back in the month the job closed.

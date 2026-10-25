# Slice 20 — Variable Compensation: Plan

Status: **proposed on 2026-10-24 for Jacob's review, after slice 19 was
merged (decision 171). Nothing is built.** Written as what the implementation
agent recommends from the plan check below.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 19:

1. **A job now runs from first call to financial close,** and the close
   produces the **final approved gross margin** (slice 19).
2. **Two kinds of pay are written in the plan and wait on that number:**
   the **sales commission** (5.7: three plan families, an advance on
   straight commission, a final amount from the actual gross margin,
   clawbacks, a ledger) and the Production Manager's **Production Bonus**
   (6.1: monthly, on gross-margin dollars above a floor, at a margin of at
   least 55%, paid the month after if that month holds too).
3. **The Finance boundary is written** (decision 133): Backoffice calculates
   an auditable variable-compensation ledger; an authorized person
   finalizes it and sends it to payroll, which may verify without
   recalculating; clawbacks stay linked to each Project.
4. **Slice 17 already keeps** whether the full deposit was collected, which
   the advance needs.

What else could be next, and why not first: Brand Ambassador closeout
(6.10) and refunds and credits (10.5) are smaller and do not affect pay;
the leaderboard (5.8) and the Performance boards are reporting on numbers
that already exist.

## What slice 20 is, and is not

Three reviewed steps. Each stops for Jacob's review.

| Step | Delivers |
| --- | --- |
| 20a | **Pay plans, and the sales commission ledger.** Each salesperson and Production Manager has a pay plan, effective from a day and kept as it was when it changes: for Sales, $45,000 + 2%, $35,000 + 6%, or straight 15% of gross-margin dollars; for Production, the Production Bonus plan. Each sold job then has commission entries for its salesperson: **projected** at the sale; the **advance** (straight commission only, 50% of projected at a 50% margin) once the customer accepted, the full deposit is collected and the handoff is complete; the **final** commission from the final gross margin once the job is financially closed, less the advance; a **clawback** if the job is cancelled; and **manual adjustments** with why. |
| 20b | **The Production Bonus.** Each month, each Production Manager's jobs completed that month (financially closed): their gross-margin dollars and margin. The bonus is (gross-margin dollars − $37,500) × 10%, earned only at a month's margin of at least 55%, and payable at the end of the following month only if that month also finishes at 55% or more. A cost found after a job closed is an adjustment in the month it is found. |
| 20c | **Finance finalizes, for payroll.** **Finance > Payroll** lists each person's entries due in a pay period; Finance finalizes the period, which marks them **scheduled**, and then **paid**, with the payroll reference. A finalized period is kept as it was; a later change is a new entry in the next period. Each person sees their own ledger. |

Not in slice 20:

- **Base salaries and payroll itself:** Gusto and payroll stay where they
  are; Backoffice sends the variable amounts.
- **W-2 painter Performance Pay** (8.8): deliberately not defined yet.
- **The $45,000 plan's "disqualifier":** decision 133 gives it no behaviour
  until Jacob defines it.

## Who may see what

| What | Who |
| --- | --- |
| Their own plan and ledger | Each salesperson and Production Manager. |
| Everyone's plans, ledgers and bonuses | Finance and the Owner. |
| Set a pay plan, add a manual adjustment, finalize a period | Finance or the Owner, each with why where it changes money. |

## Decisions needed

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **The steps and their order.** *Recommended:* 20a, 20b, 20c, each
   reviewed.
2. **Which gross margin pays.** *Recommended:* the final gross margin
   Finance approves (slice 19), before allocations, until allocations are
   built; then the plan is updated, effective from a day.
3. **The pay period.** *Recommended:* monthly: commission is due in the
   month the job is financially closed, and the Production Bonus at the end
   of the month after it is earned. Tell us if payroll runs on another
   cycle.
4. **Who sees pay.** *Recommended:* each person their own; Finance and the
   Owner everyone's; managers and Leadership do not, unless they are the
   Owner. A new permission, `compensation.manage`, for Finance and the
   Owner.
5. **Cancelled jobs.** *Recommended:* a cancelled job claws back any
   advance paid, as a negative entry in the next period, linked to the job.
6. **A sale on the base plans.** *Recommended:* the 2% and 6% plans earn
   only the final commission, with no advance (5.7: only straight commission
   gets the advance).
7. **Who the commission is for.** *Recommended:* the salesperson recorded on
   the sale; a split between two people is a manual adjustment for now.
8. **The Production Bonus's month.** *Recommended:* the month the job is
   financially closed, in the Location's time, for the Production Manager
   on the job then.

To start, Jacob approves the plan, or step 20a alone, and answers 1 to 4 and
6 and 7. Questions 5 and 8 are needed before 20b and 20c.

## Known limits of this plan

- **Pay is calculated, not paid:** Backoffice gives Finance the amounts;
  payroll pays them.
- **Before allocations** the gross margin is higher than the full one, so
  commission and bonus read high until allocations exist (question 2).

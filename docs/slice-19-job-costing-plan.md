# Slice 19 — Job Costing and the Financial Close: Plan

Status: **approved on 2026-10-24 (decision 170), with the recommendation on
all eight questions, to be built straight through 19a, 19b and 19c and then
reviewed. Being built.** Written as what the implementation agent
recommended from the plan check below.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 18:

1. **Every customer-facing step of a job is built,** from the first call to
   the last payment, with change orders. What is left of closeout is the
   **internal, financial close** (6.8): job costing complete, Finance
   reconciliation, the final gross margin, the Project financially closed.
   "PM cannot financially close before job costing is complete."
2. **Slice 18 brought the first piece:** what the job brings in after
   subcontractor payouts. It cannot be called gross margin until the other
   direct costs (10.1) are on the job: materials, rentals, permits,
   disposal, W-2 labour, warranty and others.
3. **Much waits on it:** the Production Manager's bonus is on final
   approved gross margin (6.1, 6.9); a job is complete for it only when work
   is closed, final payment is collected and job costing is complete; the
   sales commission's final amount comes from the actual final gross margin
   (5.7); Finance's "projects awaiting financial close" (9.1).
4. **The rules are already written** (6.9, decision 133): the Production
   Manager prepares, Finance validates; a submission is the provisional
   margin and Finance's reconciliation the final one; every correction is
   kept, and one above the larger of $250 or 2% of revenue, or a missing
   purchase order, is an accuracy strike, with GM/Ops told on the third in
   90 days.

What else could be next, and why not first: Brand Ambassador closeout
(6.10) is separate from the financial close and smaller; refunds and
credits (10.5) and bad debt (10.7) are rarer; commissions (5.7) and the
Production Bonus (6.1) need the final gross margin this slice produces.

## What slice 19 is, and is not

Three reviewed steps. Each stops for Jacob's review.

| Step | Delivers |
| --- | --- |
| 19a | **Costs on the job, and the budget.** The Production Manager records each direct cost: its kind (materials, equipment rental, permit, dump or disposal, W-2 labour, warranty, other), amount, vendor, day, purchase order or reference, and a photo of the receipt. Subcontractor payouts come in from the Work Packages. A **budget view** on the job, for those who may see payouts: contract, target gross margin, target cost, committed costs (payouts and costs recorded), remaining budget, projected gross margin. |
| 19b | **Submitting job costing.** Once the customer has signed off, the Production Manager submits job costing: the **provisional gross margin**, kept as it was submitted. A cost added or changed after that is a **correction**, with a reason, kept; one above the larger of $250 or 2% of revenue, or a cost with no purchase order, is an **accuracy strike** for the Production Manager (decision 133). |
| 19c | **Finance's reconciliation and the financial close.** Finance reviews a submitted job: receipts, missing purchase orders, corrections. It may reclassify a cost or add one, each kept with why, and approves the **final gross margin**. A job is **financially closed** once the customer signed off, it is paid in full and its job costing is approved. **Finance > Job Costing** lists jobs waiting for reconciliation and for close. On the third strike in 90 days, the GM/Ops managers are told. |

Not in slice 19:

- **Allocations** (insurance, payroll tax, vehicles, shop rent, 10.1 added
  2026-10-13): Finance's at the monthly close, its own plan.
- **QuickBooks**, and **commissions and the Production Bonus**: each its
  own plan; they read the final gross margin this slice produces.
- **The Brand Ambassador floor price** (10.2): the budget starts from the
  contract until Brand Ambassador tiers are built.

## Who may do what

| What | Who |
| --- | --- |
| Record and correct costs, submit job costing | The Project's Production Manager, or a manager there, who may see payouts. |
| See costs, the budget and gross margin | Those who may see payouts (`payouts.view`). |
| Reconcile, reclassify, approve the final gross margin, close | Finance (`finance.access` at the Project's Location). |
| Be told of a third strike | The managers at the Location, and Leadership. |

## Decisions needed

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **The steps and their order.** *Recommended:* 19a, 19b, 19c, each
   reviewed.
2. **The kinds of cost.** *Recommended:* materials, equipment rental,
   permit, dump or disposal, W-2 labour, warranty or callback, other; with
   subcontractor payouts from the Work Packages.
3. **Receipts.** *Recommended:* a cost can be recorded without one, but it
   is listed as "missing a receipt" until one is attached, and Finance sees
   the list (9.3).
4. **The target gross margin.** *Recommended:* 55% (the Production Bonus
   threshold, 6.1), set per Location in Settings like the deposit.
5. **The name of the figure.** *Recommended:* "Projected gross margin" once
   the job's costs are recorded, "Provisional" once submitted, "Final" once
   Finance approves; each noting it is before allocations.
6. **When job costing can be submitted.** *Recommended:* once the customer
   has signed off; a later warranty cost belongs to the month it happens
   (6.1), not the closed job.
7. **The accuracy strike** (decision 133). *Recommended:* counted
   automatically and shown to the Production Manager and their managers;
   the third in a rolling 90 days tells the Location's managers and
   Leadership by the bell; nothing else happens automatically.
8. **The financial close.** *Recommended:* closed by Finance once signed
   off, paid in full and job costing approved; it can be reopened by
   Finance with a reason. Brand Ambassador obligations do not hold it
   (6.10).

To start, Jacob approves the plan, or step 19a alone, and answers 1 to 5.
Questions 6 to 8 are needed before 19b and 19c.

## Known limits of this plan

- **Costs are typed in** from receipts and invoices; nothing comes from
  QuickBooks or a supplier yet.
- **The gross margin is before allocations,** which Finance adds at the
  monthly close.

## Step 19a as built (2026-10-24)

Built on `checkpoint-slice-19-job-costing` under decision 170.

- **Costs** (`project_costs`, `App\Actions\Projects\RecordJobCosts`): each
  direct cost with its kind (materials, equipment rental, permit, dump or
  disposal, W-2 labour, warranty or callback, other), amount, who it was
  paid to, day, purchase order and an optional note; its receipt attached
  now or later. A cost without one reads "Missing its receipt". A wrong
  cost is voided with why and stays on record; nothing is changed in place.
- **The budget** (`App\Support\Projects\JobCosting`), in a **Job costing**
  section on the job, below Payments: the contract (with signed change
  orders), the target cost for the Location's target margin, committed
  (every payout set and every cost recorded), the remaining budget (red
  below zero), and the **projected gross margin** before allocations (red
  below the target).
- **The target gross margin:** 55% by default, set per Location in
  Settings > Locations ("Target margin").
- **Who:** only those who may see payouts see the section; the job's
  Production Manager or a manager there records costs.
- **History:** each cost recorded or voided is in the audit record, never
  in the customer's Activity.
- **Tests:** `tests/Feature/Projects/JobCostsTest.php`, 10 tests.

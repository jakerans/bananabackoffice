# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-14 · **Branch:** `checkpoint-slice-13a-payout`

**State:** **slice 13, step 13a (payout) is built and waits for Jacob's
review. It is not merged.** The slice 13 plan, approved as decision 151, is
on this branch too and goes into `main` with it.

## What changed

Plan: [../slice-13-giving-work-plan.md](../slice-13-giving-work-plan.md),
"Step 13a" and "Step 13a as built".

- **Each Work Package has a payout:** what Banana will pay a subcontractor
  for it, typed in dollars, on the Project's Work and schedule part.
- **Every amount is kept,** with who set it, when, and an optional reason;
  the earlier amounts are listed under the current one.
- **Who sees it:** Production Managers, Location Managers, Leadership, the
  Owner and Finance, through a new permission, `payouts.view`. The job's
  Production Manager or a manager there sets it. Sales and the Contact
  Center never see a payout, anywhere.
- **Audit record only:** a payout is never in a customer's or a Project's
  Activity.
- **Stored:** one column on `work_packages` and a new table,
  `work_package_payouts`.

## Tests and checks

- `composer test`: formatting, PHPStan and every test; the count is in the
  chat report for this push. The new ones are in
  `tests/Feature/Projects/PayoutTest.php`; the role test lists the new
  permission.
- They cover: setting and changing, every amount kept; the same amount again
  recording nothing; six amounts that are not amounts; who may see and who
  may set, for eight kinds of person; a job that is not ready; and the page
  for the Production Manager, Finance and the salesperson, including that
  the salesperson's page carries no payout at all.
- Both GitHub jobs, including the migration on MySQL 8.4: see the chat
  report.

## Browser review (done by the agent, on the local site, fictitious data)

On the sample job scheduled in slice 11, as the sample Production Manager:

1. Both packages showed "Payout: Not set yet".
2. "a lot" was refused. Then $3,200 and $450 were set, and the first changed
   to $3,450.50 with a reason; "Was $3,200" was listed under it.
3. As the sample salesperson, the same job showed both packages with no
   payout, and the word "payout" appeared nowhere on the page.

## Architectural decisions made

- **Payouts have their own permission,** separate from seeing the Project,
  as the permission model's guardrail on finance-sensitive data asks.

## Deviations from the approved plan

None. Two readings, in "Step 13a as built", for Jacob to change if wrong:

- A reason is optional until the work can be awarded (step 13b).
- Another Production Manager at the Location sees a payout but cannot change
  it.

## Known limitations

- No award, work order or deduction yet (steps 13b and 13c).
- No gross margin is shown.

## Decisions needed

1. **Merge step 13a (and the approved slice 13 plan) into `main`, and start
   step 13b, the award?** Its questions were answered in decision 151.

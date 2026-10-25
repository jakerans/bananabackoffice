# Slice 24 — Refunds, Credits and Write-offs: Plan

Status: **proposed on 2026-10-25 for Jacob's review, after slice 23 was
merged (decision 180). Nothing is built.** Written as what the
implementation agent recommends from the plan check below.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 23:

1. **Every Center now opens on a working page except Marketing and
   Company,** and a job is recorded from first call to warranty with its
   money (slices 8 to 23).
2. **A job whose customer never pays can never close.** The financial close
   needs payment in full (decision 170, question 8), and nothing lets
   Finance write off a balance Collections has given up on, or take money
   off a bill. Those jobs stay open forever, and their commission and
   Production Bonus are never settled.
3. **The plan says how** (10.5, 10.7; decision 133): a Production Manager
   may request a refund or a credit, and Finance or the Location's manager
   approves; refunds, credits against the balance and bad-debt write-offs
   are kept apart; a write-off needs the manager and Accounting after
   Collections gives up; bad debt stays below production gross margin and
   stays linked to its job.

What else could be next, and why not first: **Marketing > Performance**
(4.1) needs marketing spend, which Backoffice does not hold; the
**Executive Board** (18) mostly gathers the boards just built and needs
company profit from QuickBooks; **My Banana**, **Referrals** and **Painter
for a Day** are large and customer-facing.

## What slice 24 is, and is not

Three reviewed steps.

| Step | Delivers |
| --- | --- |
| 24a | **Credits against the balance.** The Production Manager or a manager requests a credit on a job's final invoice, with the amount and why; Finance or a Location manager who did not request it approves or declines. An approved credit lowers what the customer owes and the job's revenue, so its gross margin and commission follow. The QuickBooks credit memo number is kept. |
| 24b | **Refunds.** The same request and approval to give money back, up to what was collected; Finance then records the refund paid, with how, the day and the reference. A refund comes off the cash collected. |
| 24c | **Bad-debt write-offs.** Once a balance has been with Collections, Finance proposes writing it off, with why; a Location manager approves (two different people). The balance closes, so the job can be financially closed. Bad debt stays below gross margin, linked to the job, and shows in its lifetime figures and on Finance > Performance. |

Not in slice 24:

- **Posting to QuickBooks:** QuickBooks owns the credit memo, refund and
  write-off entries (9.8); Backoffice keeps their reference.
- **Cancellation fees and refunds through a payment provider:** no provider
  is connected yet (22).
- **Telling the customer:** the Production Manager or Finance does, as
  today.

## Who may do what

| What | Who |
| --- | --- |
| Request a credit or a refund | The job's Production Manager or a manager there. |
| Approve or decline a credit or a refund | Finance at the Location or a manager there, never the person who requested it. |
| Record a refund paid | Finance at the Location. |
| Propose a write-off | Finance at the Location. |
| Approve a write-off | A manager at the Location, not the proposer. |

## Decisions needed

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **The steps and their order.** *Recommended:* 24a, 24b, 24c.
2. **Who approves credits and refunds.** *Recommended:* Finance at the
   Location or a manager there, never whoever requested it (10.5).
3. **What a credit changes.** *Recommended:* it lowers what the customer
   owes and the job's revenue, like a price reduction: the budget, gross
   margin, commission and Production Bonus follow it. It goes on the final
   invoice only.
4. **Refunds.** *Recommended:* never more than what was collected; approved
   like a credit, then recorded paid by Finance with how, the day and the
   reference. A refund does not change revenue by itself; a credit does.
5. **When a write-off may be proposed.** *Recommended:* only once the
   balance is with Collections (30 days after the final invoice), by
   Finance, and approved by a Location manager; two different people.
6. **Where bad debt counts.** *Recommended:* below gross margin, so the
   gross margin and the Production Bonus do not change (10.7); the job shows
   a lifetime figure after bad debt, and Finance > Performance shows the
   month's write-offs.
7. **Commission on money never collected.** *Recommended:* a write-off
   takes back the salesperson's plan rate on the written-off amount, in the
   open pay period, linked to the job (10.6: commission waits for
   collection).
8. **Where requests wait.** *Recommended:* on the job, and on Finance >
   Receivables as "Waiting for approval", for those who may approve.

## Known limits of this plan

- **A credit after the financial close** reopens nothing: Finance reopens
  the job costing first, as for any change after the close.
- **Partial collection after a write-off** is recorded as a payment, and the
  write-off is reversed by the same amount, by Finance.

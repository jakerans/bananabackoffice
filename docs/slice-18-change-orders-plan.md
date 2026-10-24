# Slice 18 — Change Orders: Plan

Status: **approved on 2026-10-24 (decision 166) in three steps, each
stopping for Jacob's review, with the recommendation on all eight
questions. Step 18a is merged (decision 167); see "Step 18a as built". Step
18b is being built.** Written as what the implementation
agent recommended from the plan check below.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 17:

1. **The money can now be followed from the sale to the last payment**
   (slice 17), but **nothing can change after the sale.** A customer who
   adds a room, or takes out a wall, has no way into the job: the final
   invoice is suggested from the price sold, and nothing records that the
   customer agreed to a new price.
2. **6.7 asks for two separate things:** a **customer change order**, which
   changes what the customer owes, signed by the customer, keeping the
   original agreement; and a **subcontractor work order revision**, which
   changes what Banana owes a subcontractor or what they do, re-signed when
   it matters, with or without a customer change. It also asks to **show
   the margin impact**.
3. **Already settled by Finance:** approved "add work" creates a
   customer-signed change order and is added to the Project's invoice
   (Finance answers, 10.7); change orders update the customer's lifetime
   sold value (21).
4. **A gap today:** a Work Package's scope can be edited after it was given
   to a subcontractor, with nothing recorded for the subcontractor. Only a
   payout change asks for a reason.

What else could be next, and why not first: job costing and the financial
close (6.9) need the change orders to know the final revenue; Brand
Ambassador closeout (6.10) and refunds and credits (10.5) are smaller and
can follow.

## What slice 18 is, and is not

Three reviewed steps. Each stops for Jacob's review.

| Step | Delivers |
| --- | --- |
| 18a | **Customer change orders.** On a job: what changes, in words; the price change before tax, up or down; the working days it adds, if any; and the customer's signature: who signed, how, and the day, with the **signed copy attached** (a photo or PDF, as in slice 16). A change order is a draft until signed; a draft can be withdrawn. Signed ones are kept as they were; a mistake is put right by another change order. The job shows the price sold, each signed change order, and the **contract total**; the final invoice's suggested amount includes them. |
| 18b | **Subcontractor work order revisions.** Once a Work Package is given out, changing its scope or its payout is a revision: what changes, why, and, linked if it comes from one, the customer change order. The subcontractor's acceptance is recorded (who, how, when), and the revised work order is sent again (as in slice 13). Until the subcontractor accepts, the job shows the revision as waiting. |
| 18c | **What the job brings in after payouts.** For those who may see payouts: the contract total, less every subcontractor payout, in dollars and as a share, before and after each change; on the job, and on the change order and revision dialogs before they are saved. Called "after subcontractor payouts", not gross margin, until job costing (6.9) brings in every other cost. |

Not in slice 18:

- **Sending anything to the customer or subcontractor** for signature.
  Signatures happen as they do today; Backoffice keeps the signed copy.
- **Job costing, materials and the financial close** (6.9), **refunds and
  credits** (10.5), **commissions** (5.7): each its own plan.
- **Changing Sales figures.** A change order does not change the sale's
  month, close rate or average job size.

## Who may do what

| What | Who |
| --- | --- |
| Draft, sign off and withdraw a customer change order | The Project's Production Manager, or a manager there. |
| A change order that lowers the price | A manager there, with a reason. |
| Record a subcontractor revision and its acceptance | The Production Manager or a manager there, who may see payouts. |
| See change orders | Everyone who may see the Project. |
| See the amounts after payouts | Those who may see payouts (`payouts.view`). |

## Decisions needed

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **The steps and their order.** *Recommended:* 18a, 18b, 18c, each
   reviewed.
2. **Proof of the customer's signature.** *Recommended:* the signed change
   order is attached, a photo or PDF, before it counts; with who signed,
   how (in person, email, text) and the day.
3. **Lowering the price.** *Recommended:* only a manager, with a reason,
   since it is a credit to the customer.
4. **The final invoice.** *Recommended:* its suggested amount is the price
   sold plus every signed change order, less the deposit invoice, before
   tax; the total is still recorded as QuickBooks shows it (decision 162).
5. **Sales figures.** *Recommended:* change orders do not change the sale's
   figures on Sales > Performance; they count toward the customer's
   lifetime value when that is built.
6. **The schedule.** *Recommended:* a change order records the working days
   it adds; the dates themselves change through the existing reschedule or
   "More days", so nothing moves without the Production Manager.
7. **Which subcontractor changes need their acceptance.** *Recommended:*
   every change of scope or payout once the work is given out, with the work
   order sent again; a change before the award is simply an edit, as today.
8. **The name of the figure in 18c.** *Recommended:* "After subcontractor
   payouts", in dollars and percent, never "gross margin" until every cost
   is counted (6.9).

To start, Jacob approves the plan, or step 18a alone, and answers 1 to 6.
Questions 7 and 8 are needed before 18b and 18c.

## Known limits of this plan

- **Signatures are collected outside Backoffice;** it keeps the signed copy.
- **Change orders on jobs already invoiced in full** are possible, and
  leave a balance owed: a new final invoice is recorded after voiding the
  old one (slice 17), as today.

## Step 18a as built (2026-10-24)

Built on `checkpoint-slice-18a-change-orders` under decision 166.

- **Where:** a **Change orders** section on every job page, above
  Payments: the price sold, the signed changes, and the **contract total**
  before tax; each change order with its number, price change, what
  changes, the days it adds, and who drafted, signed or withdrew it.
- **Drafting** (`App\Actions\Projects\RecordChangeOrders`): what changes,
  the price change in dollars before tax ("-" when it comes down, "0" for
  none), and the working days it adds (0 to 60). Numbered 1, 2, 3 on its
  job. A draft does not count.
- **Lowering the price** is a manager's, with a reason (question 3). A
  Production Manager who tries is told so on the field.
- **Signing:** who signed, how (in person, by email or by text; not by
  phone, as a signature needs a signed copy), the day, and the signed copy
  attached (question 2). From then on it counts in the contract total and
  is kept as it was.
- **Withdrawing** a draft the customer will not sign, with why. A signed
  one is never withdrawn: a mistake is put right by another change order.
- **The final invoice** is suggested from the contract total less the
  deposit invoice (question 4). The price sold, and so the Sales figures,
  do not change (question 5).
- **Who** (`ProjectPolicy::manageChangeOrders`): the job's Production
  Manager or a manager there; everyone who may see the job reads them.
- **History:** drafted, signed and withdrawn are each in the job's
  activity, with the price change.
- **Tests:** `tests/Feature/Projects/ChangeOrdersTest.php`, 18 tests.

### Known limits

- **The working days a change order adds are recorded, not scheduled:**
  the dates move through the existing reschedule or "More days" (question
  6).
- **A subcontractor is not told yet:** that is step 18b.

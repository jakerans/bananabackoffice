# Slice 17 — Customer Payments: Plan

Status: **approved on 2026-10-22 (decision 162) in three steps, each stopping
for Jacob's review, with the recommendation on all eight questions. Step 17a
is being built.** Written as what the implementation agent recommended from
the plan check below. **Step 17a is merged (decision 163); see "Step 17a as
built". Step 17b is merged (decision 164); see "Step 17b as built". Step 17c
is built and awaiting Jacob's review; see "Step 17c as built".**

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 16:

1. **The job now runs from first call to the customer's signoff** (slices 1
   to 16). The one customer-facing step of closeout still missing is the
   last one: **"final balance collected"** (6.8).
2. **No money from the customer is recorded anywhere.** The deposit (10.4),
   what is owed and when (10.6, 9.2) and the payment state of a Project (a
   separate state dimension, section 2) do not exist yet.
3. **Much waits on it:** the deposit-paid handoff call (6.3, 10.6), a job
   that is "complete" for the Production Bonus only once final payment is
   collected (6.1), the sales commission advance, which needs a full 50%
   deposit actually collected (5.7), and Finance > Receivables (9.2).
4. **The Finance boundary is already decided** (decision 133): Backoffice
   records what is expected and what was paid, with QuickBooks' and the
   payment processor's references; QuickBooks owns posting, tax and
   reconciliation. Cash and check need receipt evidence and Finance approval,
   and slice 16 now stores the receipt.

Change orders (6.7) are the natural slice after this one: they change what
the customer owes, so they build on what this slice records.

## What slice 17 is, and is not

Three reviewed steps. Each stops for Jacob's review.

| Step | Delivers |
| --- | --- |
| 17a | **What the customer owes and what they paid.** On each Project: the deposit invoice and the final invoice, as sent from QuickBooks (amount with tax, its number, the day sent); each payment received against them (amount, method, day, the processor's or QuickBooks' reference); the balance; and the Project's **payment state**: Deposit due, Deposit paid, Balance due, Paid in full. Cash and check need a photo of the receipt and Finance's approval before they count. A wrong payment is reversed by a correcting entry; nothing is deleted. |
| 17b | **The deposit.** The deposit expected from each Location's policy (50% by default, set in Settings > Locations); a smaller or no deposit only with a manager's approval and a reason; "full deposit collected" kept for the commission advance later; and when the deposit is paid, the Production Manager is told and has 24 hours to call the customer (10.6), shown on Production > Workspace. |
| 17c | **The final balance and who chases it.** The final balance is due 7 days after the final invoice. Each Project shows the day it is due and how late it is; the Production Manager's list of what to chase today (daily for the first three days, then on days 7 and 14, then weekly); at 30 days it moves to **Collections**. **Finance > Receivables** lists every open balance by age (current, 1–7, 8–14, 15–30, over 30 days), by Location, with its owner. |

Not in slice 17:

- **Sending anything to the customer** (invoices, notices, payment links):
  invoices come from QuickBooks as today; no message to customers is
  approved.
- **Taking card or bank payments** in Backoffice, or any payment provider.
- **The QuickBooks connection.** References are typed in for now.
- **Change orders** (6.7), **refunds and credits** (10.5), **bad debt**
  (10.7), **job costing** (6.9), **commissions** (5.7): each its own plan.

## Who may do what

| What | Who |
| --- | --- |
| See a Project's invoices, payments and balance | Everyone who may see the Project, and Finance. |
| Record an invoice or a card, bank or financing payment | The Project's Production Manager, a manager there, or Finance. |
| Record a cash or check payment | The same, with a photo of the receipt; it counts only once Finance approves it. |
| Approve cash and check, reverse a payment | Finance (`finance.access` at the Project's Location), with a reason for a reversal. |
| Lower or waive a deposit | A manager at the Location, with a reason. |
| Set a Location's deposit policy | Whoever manages Locations. |
| See Finance > Receivables | Finance, and managers for their Locations. |

## Decisions needed

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **The steps and their order.** *Recommended:* 17a, 17b, 17c, each
   reviewed.
2. **Amounts come from QuickBooks.** QuickBooks works out the tax, so
   Backoffice cannot know an invoice's total by itself. *Recommended:* the
   person records each invoice's total as QuickBooks shows it, with its
   invoice number; Backoffice suggests the deposit as the policy's share of
   the sold price before tax.
3. **Who records payments.** *Recommended:* as in the table: the
   Production Manager, a manager or Finance; cash and check only with a
   receipt photo and Finance's approval (decision 133).
4. **The deposit policy.** *Recommended:* a percent per Location, 50% by
   default; less, or none, only by a manager with a reason. (The PFAD rule of
   75% (8.9) waits for PFAD.)
5. **The late fee at day 7.** Its amount is not written down. *Recommended:*
   Backoffice marks the balance late and shows "late fee applies"; the fee
   itself is added in QuickBooks and recorded as part of the final invoice
   total. Tell us the amount if it should be shown.
6. **Collections at 30 days.** *Recommended:* Collections is Finance for
   now: the balance moves to Finance > Receivables as "Collections" and
   leaves the Production Manager's list.
7. **Card and financing surcharges** (passed to the customer). *Recommended:*
   included in the invoice total from QuickBooks; nothing separate in
   Backoffice for now.
8. **The deposit-paid call.** *Recommended:* the Production Manager is told
   by the bell, and the call is a tick on the Project, due within 24 hours,
   shown late on Production > Workspace once missed.

To start, Jacob approves the plan, or step 17a alone, and answers 1 to 3.
Questions 4 and 8 are needed before 17b; 5 and 6 before 17c.

## Known limits of this plan

- **Typing references by hand** until QuickBooks is connected; a mistyped
  amount is corrected by a reversing entry.
- **No customer notices are sent;** the Production Manager is shown whom to
  contact and when.
- **Existing Projects** start with no invoices recorded; their payment state
  reads "Deposit due" until someone records what was invoiced and paid.

## Step 17a as built (2026-10-22)

Built on `checkpoint-slice-17a-payments` under decision 162.

- **Where:** a **Payments** card on every job page, above "What was sold",
  kept on a cancelled job too, and a "Payment: ..." badge beside the job's
  other states in its header.
- **Invoices** (`project_invoices`): the deposit and the final invoice, one
  of each at a time, with QuickBooks' number, the total with tax and the day
  sent. The amount is suggested before tax (the deposit as half the sold
  price, the final as the sold price less the deposit invoice) and changed
  to what QuickBooks shows. A wrong invoice is **voided** with a reason,
  only once no payment stands against it, and recorded again.
- **Payments** (`project_payments`): against one invoice, never more than
  is still owed on it; card, bank transfer (ACH), check, cash or financing;
  the day received; the processor's or QuickBooks' reference, required
  except for cash and check. **Cash and check** need a photo or scan of the
  receipt (stored as in slice 16) and count only once **Finance approves**
  them, in a confirmation that shows the amount and asks to check the
  receipt. **Finance reverses** a payment with a reason; it stops counting
  and stays on record, crossed out.
- **The payment state** (`App\Support\Projects\Payments`), worked out from
  the records: Deposit due until the deposit invoice is paid, then Deposit
  paid, then Balance due once there is a final invoice, then Paid in full.
  The card shows what was invoiced, paid, the balance, and any cash or
  check waiting for approval.
- **Who** (`ProjectPolicy::recordPayments` and `approvePayments`): the
  Production Manager, a manager there or Finance records; Finance approves
  and reverses. A deposit can be recorded before the job has a Production
  Manager. Everyone who may see the job reads it.
- **History:** every invoice recorded or voided and every payment recorded,
  approved or reversed is in the job's activity, with amounts.
- **Nothing is changed in place or deleted:** invoices and payments refuse
  any change but voiding, approving and reversing.
- **Tests:** `tests/Feature/Projects/PaymentsTest.php`, 21 tests.

### Known limits

- **Finance finds cash and check waiting for approval on each job page**
  for now; 17c's Finance > Receivables lists them in one place.
- **Whoever recorded a cash payment may also approve it,** if they hold
  Finance. Decision 163: someone else approves, once they see the money in
  the bank; built in 17b.
- **Existing jobs** read "Deposit due" until their invoices are recorded.

## Step 17b as built (2026-10-23)

Built on `checkpoint-slice-17b-deposit` under decisions 162 and 163.

- **The deposit policy:** a percent of the sold price before tax for each
  Location, 50 by default, in Settings > Locations ("Deposit"), set by
  whoever manages Locations. A change applies to every job there whose
  deposit a manager did not change; invoices already recorded stay as they
  are.
- **A smaller deposit, or none,** on one job: "Change the deposit" in its
  Payments section, by a manager there (`projects.manage`), with a reason,
  never above the policy's amount, and only until the deposit invoice is
  recorded. Each change is kept; the latest counts. "0" waives it, and the
  job then reads **No deposit**. The deposit invoice's suggested amount is
  the deposit asked for.
- **The full deposit** is recorded as collected only when what was paid on
  the deposit invoice reaches the policy's amount, for the sales commission
  advance later (5.7); a lowered or waived deposit is not a full one.
- **Deposit paid:** the moment the deposit invoice is paid in full (after
  Finance's approval, for cash and check), the job keeps when, and its
  Production Manager is told by the bell, unless the customer was called
  already. A reversal that leaves it unpaid clears it.
- **The call within 24 hours** is the **welcome call** the Production
  Manager already ticks (slice 10): once the deposit is paid, it is due 24
  hours after that, or after the Production Manager got the job if that was
  later, and shows as falling behind on the job and on Production >
  Workspace once missed. Before the deposit is paid, the slice 10 rule of
  two business days stands. One call, one tick: the agent's reading of
  decision 162, question 8, which Jacob let stand (decision 164).
- **Approving cash and check** (decision 163): the confirmation asks "Is it
  in the bank account?", and whoever recorded the payment cannot approve
  it, unless they are the Owner.
- **History:** a deposit lowered or waived is in the job's activity, with
  the reason; a policy change is in the audit record.
- **Tests:** `tests/Feature/Projects/DepositTest.php`, 22 tests.

## Step 17c as built (2026-10-24)

Built on `checkpoint-slice-17c-receivables` under decisions 162 and 164.

- **When the final balance is due, and who chases it**
  (`App\Support\Projects\Payments`), all counted in days since the final
  invoice was sent, where the job is (product-architecture.md, 10.6):
  - due on day 7; from then it is **late**, and the job says a late fee
    applies, to be added to the invoice in QuickBooks (question 5);
  - to be chased on days 1, 2 and 3, on days 7 and 14, then every seven
    days (21, 28);
  - from day 30 it is with **Collections**, which is Finance (question 6):
    it leaves the Production Manager's list.
- **Contacts** (`project_collection_contacts`): "Record a contact" on the
  job's Payments section, with an optional note, by the Production Manager,
  a manager or Finance. A contact takes the job off that day's list; the
  latest five show on the job, and each is in the job's activity.
- **Production > Workspace** shows **Balances to chase today**, only when
  there are some: the jobs at the Location on a chase day that nobody has
  contacted today, the person's own first, then the longest owed.
- **Finance > Receivables** (`/finance/receivables`, now live in the
  Finance menu), for those with `finance.access`, over the Locations where
  they hold it, or one of them: the total owed; cash and check waiting for
  approval, each linking to its job; every final balance owed, grouped Not
  yet due, Late days 7 to 13, Late days 14 to 29, and Collections at 30 days
  or more, with its total, who chases it and when the customer was last
  contacted; and deposits invoiced and not yet paid. It only reads:
  everything is recorded on each job.
- **Tests:** `tests/Feature/Projects/ReceivablesTest.php`, 10 tests.

### Deviations from the plan

1. **The groups follow the collection timeline**, counted from the final
   invoice (not yet due, days 7 to 13, 14 to 29, 30 and over), rather than
   the plan's "current, 1–7, 8–14, 15–30, over 30 days", so each group
   matches a step of 10.6: the late fee at day 7, the escalation at day 14,
   Collections at day 30.
2. **Receivables is Finance's.** The plan also named managers, who do not
   have the Finance Center; they see each job's balance on the job and the
   balances to chase on Production > Workspace.

### Known limits

- **Nothing is sent to the customer:** the notices of 10.6 (before the
  late fee, the day-14 escalation) are the Production Manager's calls and
  QuickBooks' own reminders. Backoffice says whom to contact and when.
- **Business days are not counted:** the chase days fall on weekends too.

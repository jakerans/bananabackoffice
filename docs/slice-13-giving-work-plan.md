# Slice 13 — Giving Work to a Subcontractor: Payout, Award and the Work Order: Proposed Plan

Status: **proposed on 2026-10-14, after slice 12 (decision 150). Not
approved. Nothing in it is built.** It is what the implementation agent
recommends next, from the plan. Jacob approves it, changes it, or chooses
something else.

It follows [product-architecture.md](product-architecture.md), sections 6.6,
7.4 to 7.8 and 10.1, the stakeholder answers on the insurance deduction
([stakeholder-discovery-open-questions.md](stakeholder-discovery-open-questions.md),
questions 9 and 19; decision 133), and builds on slices 11 and 12.

## Why this is next, from the plan

1. **A scheduled job still has nobody to do it.** Slices 11 and 12 built the
   work, its dates and the subcontractors. Joining them is what the plan
   describes next: a Work Package has "payout ... award status, accepted
   subcontractor, signed agreement" (6.6).
2. **It unlocks what slice 11 left open:** the crew-selected window (6.5,
   7.8), and approved colors before a work order goes out (decision 141).
3. **It can be done inside Backoffice.** The plan's marketplace, where
   subcontractors accept and sign for themselves (7.4), needs them to sign
   in. The plan also has "direct to one subcontractor" as a release strategy
   (7.4). This slice builds that one, recorded by the Production Manager;
   the marketplace and the portal come later.
4. **What else could have been next.** Document storage needs the private
   storage of decision 33 set up first. Average Job Size and
   Close-on-the-Spot Rate (5.2) are small and can be done at any time. The
   deposit and payments need the QuickBooks check.

## What the plan already settles

| The plan says | Where |
| --- | --- |
| A Work Package has a payout, an award, the accepted subcontractor, the signed agreement and payout revisions. | 6.6 |
| Payout revisions are versioned. | 7.6 |
| One release strategy is "direct to one subcontractor". | 7.4 |
| The Production Manager offers windows the customer agreed to; the subcontractor picks one; the Production Manager reconfirms with the customer. | 7.8 |
| A reassignment the customer or Banana asked for is not counted against the subcontractor. | 7.8 |
| A subcontractor using Banana's coverage has a deduction, 10% of payout to start, effective-dated, overridable by Location or state, shown in dollars on the work order. The gross payout is kept; the deduction is a separate figure. | 7.2; questions 9 and 19; decision 133 |
| Subcontractor payouts are finance-sensitive and need their own permission. | Permission model, guardrails |
| Approved colors are required before a work order goes to a crew. | Decision 141 |
| Signed subcontractor payouts are committed costs. | 10.3; question 22 |

## What slice 13 is, and is not

Three reviewed steps. Each stops for Jacob's review.

| Step | Delivers | From |
| --- | --- | --- |
| 13a | **Payout.** Each Work Package's payout in dollars, changed with a reason and every amount kept. Seen only by those allowed. | Plan 6.6, 7.6 |
| 13b | **The award.** The Production Manager gives a package to one compliant subcontractor, who picks one of the windows offered, with how they accepted; changing or taking back an award with whose doing it was. | Plan 7.4, 7.8 |
| 13c | **The work order.** A page for each awarded package: what to do, when, where, the colors, the payout and any deduction. It can be printed. Approved colors are needed first. | Plan 6.6, 7.2; decision 141 |

Not in slice 13, each needing its own plan and approval:

- **The marketplace and the portal** (7.4, 7.5): subcontractors seeing work,
  accepting and signing for themselves; pre-award privacy; declines and
  price intelligence (7.6).
- **Electronic signature.** The master subcontract agreement is on file
  from compliance; this slice records how each award was accepted.
- **The PM Budget View and gross margin** (10.2, 10.3): they need the Brand
  Ambassador floor and target figures, which are not built.
- **Paying the subcontractor:** payables, QuickBooks and Gusto (9.8;
  questions 24 and 25).
- **Overruns, completion and the scorecard** (7.9, 7.10).
- **Utilization** (decision 133): it can follow once awards exist.
- **Any message** to a subcontractor or a customer.

## Step 13a — Payout

### What a person sees

- **On each Work Package,** for those allowed to see it: the payout in
  dollars, set by the Production Manager or a manager.
- **Changing a payout** after it is first set needs a reason; each amount is
  kept with who set it and when.
- **Those not allowed** (Sales, the Contact Center) see the package without
  its payout.

### What it stores

Each payout amount a package has had, with who set it, when and why.

## Step 13b — The award

### What a person sees

- **"Give this work to a subcontractor"** on a package with a payout: a
  choice of the subcontractors who are Active, compliant and work at the
  job's Location. One whose crew is smaller than the package needs is shown
  with a warning.
- **The subcontractor picks a window:** one of those offered to the
  customer, or the confirmed dates if the customer has already confirmed.
  The Production Manager then confirms with the customer, as now (7.8).
- **How they accepted:** in person, by phone, by text or by email, and the
  day, as for the customer's confirmations.
- **Changing or taking back an award** needs a reason and says whose doing
  it was: the subcontractor withdrew, or the customer or Banana asked for
  it (7.8). The earlier award is kept.
- **The Schedule page, the Workspace and the Project** show who has the
  work.

### What it stores

Each award of a package: the subcontractor, the payout at the time, the
window chosen, how and when they accepted, and, if it ended, when, why and
whose doing it was.

## Step 13c — The work order

### What a person sees

- **A work order page for each awarded package:** the job's address and
  access notes from the handoff; the scope, the dates and the working days;
  the smallest crew; the approved colors for the job; the payout, and for a
  subcontractor using Banana's coverage the deduction in dollars and what
  is left. It prints cleanly.
- **"Work order sent"** records that the Production Manager sent it, how and
  when. Backoffice sends nothing.
- **Approved colors are needed** before a work order can be marked sent
  (decision 141). Until then the page says what is missing.
- **A change to the payout or dates after it was sent** marks it as changed
  since it was sent.

### What it stores

When each work order was sent, how and by whom; and the deduction rate, a
setting that starts at 10% and keeps every earlier rate with the day it
changed.

## Who may do what

| What | Who |
| --- | --- |
| See payouts and deductions | A new permission, `payouts.view`: the Production Manager, Location Manager and Leadership roles at the Location, the Owner, and Finance. |
| Set payouts, award, change or take back an award, mark a work order sent | The Project's Production Manager, or anyone with `projects.manage` there, who also holds `payouts.view`. |
| Change the deduction rate | Whoever manages Locations (`settings.locations.manage`). |
| See who has the work, without the payout | Everyone who may see the Project. |

## Audit and Activity

Every change is an audit event saved with it. In the customer's Activity:
work given to a subcontractor (by business name), an award changed or taken
back, and a work order sent. Payout amounts are never in the customer's
Activity; they are in the audit record only.

## Tests

As slices 8 to 12 had: who may and may not, as a matrix of roles and
Locations, with payouts hidden from everyone without the permission, on
every page; every refusal; nothing half-saved; history kept exactly; and the
migrations on MySQL 8.4, with every stored name and key name checked against
its limit.

## Decisions needed

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **Who sees payouts.** *Recommended:* a new permission held by Production
   Managers, Location Managers, Leadership, the Owner and Finance. Sales and
   the Contact Center never see them. *Alternative:* managers and Finance
   only, and Production Managers see the package without its payout.
2. **Changing a payout.** *Recommended:* free until the work is awarded;
   after that, with a reason, and the subcontractor's acceptance recorded
   again. Every amount is kept.
3. **Who can be given the work.** *Recommended:* only Active, compliant
   subcontractors who work at the job's Location; a crew smaller than the
   package needs is a warning, not a refusal. *Alternative:* refuse a crew
   that is too small.
4. **The order of things.** *Recommended:* the subcontractor picks one of the
   windows already offered to the customer, and the Production Manager then
   confirms with the customer as now; if the customer has already confirmed,
   the award is for those dates. *Alternative:* award only after the
   customer has confirmed.
5. **How acceptance is recorded.** *Recommended:* who accepted, how (in
   person, phone, text, email) and the day, recorded by the Production
   Manager; the master agreement is already required by compliance.
6. **Taking back an award.** *Recommended:* a reason every time, and whose
   doing it was: "the subcontractor withdrew" or "the customer or Banana
   asked". Only the first will count against them when the scorecard comes.
7. **The insurance deduction.** *Recommended:* one company rate, starting at
   10% of payout, changed in Settings with the day it takes effect; Location
   or state rates when a Location needs one. *Alternative:* a rate per
   Location from the start.
8. **The work order.** *Recommended:* a page in Backoffice that prints;
   "sent" is recorded by the Production Manager. Approved colors are needed
   before it is marked sent.
9. **The job's address on the work order.** *Recommended:* yes, on the work
   order once awarded, as the plan allows after award (7.5); never before.
10. **No message** to subcontractors or customers in this slice.
    *Recommended:* none.

To start, Jacob needs to approve the plan, or step 13a alone, and answer
questions 1 and 2. Questions 3 to 6 are needed before 13b, and 7 to 9
before 13c.

## Known limits of this plan

- **Acceptance is somebody's word** that the subcontractor accepted, until
  they can sign for themselves.
- **No gross margin is shown.** Payouts are committed costs, but the budget
  view needs figures that are not built yet.
- **Nobody is paid by Backoffice.** Payables stay where they are today.

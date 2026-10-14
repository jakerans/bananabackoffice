# Slice 12 — Subcontractors: the Roster and Compliance: Plan

Status: **approved on 2026-10-14 (decision 148) in two steps, each stopping
for Jacob's review, with the recommendation on all ten questions. Step 12a was
built and merged on 2026-10-14 (decision 149); see "Step 12a as built". Step
12b was built and merged on 2026-10-14 (decision 150); see "Step 12b as
built". Slice 12 is complete.** It was written as what the implementation agent recommended
after slice 11, from the plan.

It follows [product-architecture.md](product-architecture.md), section 7
(7.1 to 7.3 and 7.11), and the stakeholder answers on subcontractor
compliance ([stakeholder-discovery-open-questions.md](stakeholder-discovery-open-questions.md),
question 10; decision 133).

## Why this is next, from the plan

1. **Everything after scheduling waits for crews.** The crew-selected window
   (6.5, 7.8), utilization (decision 133), payout and work orders (6.6), the
   marketplace (7.4) and approved colors before a work order (decision 141)
   all need subcontractors to exist in Backoffice.
2. **The plan starts with who they are.** Section 7 runs recruit, onboard,
   roster, then marketplace, overrun and scorecard. The roster and its
   compliance come first; the marketplace attaches to them.
3. **Production > Subcontractors is already in the menu,** waiting.
4. **What else could have been next.** Average Job Size and
   Close-on-the-Spot Rate (5.2) are small and can be done at any time. The
   deposit and payments (10.4, 10.6, 22) need the QuickBooks integration
   check first.

## What the plan already settles

| The plan says | Where |
| --- | --- |
| Production owns recruiting, onboarding, the roster, benching and offboarding, on a page of its own. | 7 |
| Recruiting runs Prospect, Screened, Interview Scheduled, Interviewed, Approved/Hired, Onboarding Sent, Active Roster. | 7.1 |
| Onboarding gathers business and contact details, crew size, services, home base, tax and legal documents, insurance, licenses and the agreement. | 7.2 |
| The base compliance checklist is W-9, general liability, workers' compensation with waiver of subrogation, the signed subcontractor agreement, and the annually renewed New Mexico gross-receipts-tax waiver certificate. Location or state additions are configurable. | 7.2; question 10 |
| A subcontractor without the required insurance may use Banana's coverage, with a deduction from payout (10% to start, effective-dated). | 7.2; decision 133 |
| Roster statuses: Active, VIP, Benched, Offboarded, Pending Compliance. Benched keeps the history and gets no new work. | 7.3 |
| VIP is never automatic. Eligibility can be shown; the Production Manager decides. | 7.11; question 10 answers |
| Files live in private storage of their own, reached only by a permission check and a short-lived link. | Decision 33 |

## What slice 12 is, and is not

Two reviewed steps. Each stops for Jacob's review.

| Step | Delivers | From |
| --- | --- | --- |
| 12a | **The roster and recruiting.** Production > Subcontractors: each subcontractor's details, where they are in recruiting, and their roster status, with its history. | Plan 7, 7.1, 7.3 |
| 12b | **Compliance.** The checklist on each subcontractor, what is current and what expires, and "Pending compliance" worked out from it. Extra requirements per Location. | Plan 7.2, 7.3 |

Not in slice 12, each needing its own plan and approval:

- **The documents themselves.** Keeping a W-9 or an insurance certificate
  means storing files, which needs the private storage of decision 33 and a
  backup for it (security-notes.md, "Database backups do NOT cover uploaded
  files"). Slice 12 records that each item was received and when it expires,
  not the file. See question 6.
- **A portal for subcontractors** to sign in and fill in their own details
  (7.2). No one outside Banana signs in to Backoffice yet.
- **Giving work to a subcontractor:** the marketplace, a direct award, the
  crew-selected window, payout and work orders (7.4 to 7.9).
- **The scorecard and VIP eligibility figures** (7.10, 7.11). They need
  finished jobs.
- **The insurance deduction in dollars.** It applies to payouts, which do not
  exist yet. Slice 12 records only that a subcontractor uses Banana's
  coverage.

## Step 12a — The roster and recruiting

### What a person sees

- **Production > Subcontractors** becomes a live page: the subcontractors
  of the Location shown, by where they stand, with a search by name.
- **Add a subcontractor:** the business name; the contact person, phone and
  email; how many people are in the crew; which services they do (the same
  list Sales uses); and their home base as a town and ZIP code.
- **Where they stand,** one at a time, each change kept with who made it and
  when: Prospect, Screened, Interview scheduled, Interviewed, Approved,
  Onboarding sent, Active, Benched, Offboarded, and Not hired for one who
  does not go ahead.
- **VIP** is a mark on an Active subcontractor, set or taken off with a
  reason.
- **Benching** needs a reason; a benched subcontractor stays listed with
  their history. **Offboarding** needs a reason too.
- **Each subcontractor has a page** with their details, their history, and
  (from 12b) their compliance.

### What it stores

Subcontractors, the Locations they work at, their services, and every
change of where they stand, with the reason when one is needed.

### Step 12a as built (2026-10-14)

**Merged into `main` on 2026-10-14** with Jacob's word ("continue", decision
149). Built as planned above.
What a reader of the code should know:

- **Stored:** `subcontractors` (business name, contact person, phone, email,
  crew size, home town and ZIP, where they stand, VIP), with
  `location_subcontractor` and `service_subcontractor`. A subcontractor is
  never deleted. No tax ID number is kept.
- **History is the audit record** (decision 34): added, details changed,
  each change of where they stand, VIP set or taken off, each with who, when
  and the reason. It is shown on the subcontractor's page, and is in no
  customer's Activity.
- **Two new permissions,** `subcontractors.view` and
  `subcontractors.manage`, held at a Location by the Production Manager,
  Location Manager and Leadership roles, and by the Owner. Sales, the Contact
  Center and Finance have neither. `App\Policies\SubcontractorPolicy`: a
  person may see or change a subcontractor who works at one of the Locations
  where they hold the permission.
- **One action writes it all:** `App\Actions\Subcontractors\ChangeSubcontractor`
  (add, change, moveTo, setVip), with the record held and the permission
  checked each time.
- **Production > Subcontractors** is live: one Location's subcontractors in
  three parts, "On the roster" (Active and Benched), "Recruiting" (Prospect
  to Onboarding sent) and "Past" (Offboarded and Not hired), with a search
  by business or contact name. "Add a subcontractor" opens on their new
  page.
- **Locations:** a person chooses only Locations where they look after
  subcontractors; when they change one, its other Locations stay as they
  are. At least one is needed.
- **Where they stand** can be changed to any other standing, one change at a
  time. Benched, Offboarded and Not hired need a reason, and so does moving
  out of them. Leaving Active takes VIP off.

Readings made while building, for Jacob to change if wrong:

- **Any standing can follow any other,** not only the next in recruiting,
  so a step can be skipped or undone. Each change is kept.
- **A reason is optional for the ordinary recruiting steps** and asked for
  as a note.
- **A service taken off Sales' list stays on a subcontractor who has it,**
  but cannot be chosen again.
## Step 12b — Compliance

### What a person sees

- **The checklist on each subcontractor:** W-9; general liability insurance;
  workers' compensation with waiver of subrogation; the signed subcontractor
  agreement; and, where required, the New Mexico gross-receipts-tax waiver
  certificate. Each is ticked with the day it was received and, for
  insurance and the waiver certificate, the day it expires.
- **Insurance can be "uses Banana's coverage"** instead, which is recorded
  and shown, for the deduction a later slice will apply.
- **"Pending compliance"** is worked out from the checklist: anything
  required that is missing or expired. It shows on the roster and on the
  subcontractor's page, with what is missing. Nothing is ticked by hand.
- **Expiring soon:** an item that expires within 30 days is marked, on the
  page and the roster.
- **Extra requirements per Location** (a license, say): a manager adds them
  in Settings, each with whether it expires.

### What it stores

Each compliance item received, with its days and who recorded it, kept when
it is renewed; and each Location's extra requirements. No tax ID number and
no document is stored.

### Step 12b as built (2026-10-14)

**Merged into `main` on 2026-10-14** with Jacob's word ("continue", decision
150). Built as planned above.
What a reader of the code should know:

- **Stored:** `compliance_requirements`, with the four of the base checklist
  written in by the migration (W-9; general liability insurance and workers'
  compensation with waiver of subrogation, which expire and may be covered
  by Banana; the signed subcontractor agreement), and each Location's own;
  and `subcontractor_compliance_records`, one for each time an item was
  received, never changed or deleted. A requirement taken away is kept, not
  active.
- **One class works compliance out:** `App\Support\Subcontractors\Compliance`.
  Each item is missing, received, expiring soon (within 30 days), expired,
  or covered by Banana. A subcontractor is compliant when nothing is missing
  or expired. Nothing is stored for it.
- **Recording** (`App\Actions\Subcontractors\RecordCompliance`): the day
  received, which cannot be in the future; for an item that expires, the
  day it expires, which cannot have passed; or Banana's coverage, where the
  item allows it; and an optional note. Recording again renews it, and the
  earlier record stays. The Production Manager or a manager at one of the
  subcontractor's Locations.
- **On the subcontractor's page,** a Compliance card lists each item with
  its state and what was recorded, says what is still needed, and offers
  "Record" or "Renew or correct". A subcontractor on the roster shows
  "Compliant" or "Pending compliance" beside where they stand.
- **On the roster list,** "Pending compliance" or "Expires soon".
- **Settings > Locations** has "Subcontractor requirements" for each
  Location: the base checklist, the Location's own, adding one (with
  whether it expires) and taking one away. "Fill in the New Mexico waiver
  certificate" fills in its name and that it expires. Whoever manages
  Locations (`settings.locations.manage`) changes them.

Readings made while building, for Jacob to change if wrong:

- **No Location has the New Mexico waiver until someone adds it,** because
  Backoffice does not know which Locations are in New Mexico. It takes one
  click in Settings.
- **"Pending compliance" shows only for subcontractors on the roster,** not
  for those still being recruited, who are not expected to have everything
  yet. Their checklist still shows on their page.
- **A correction is a new record,** like a renewal; the wrong one stays in
  the history.
## Who may do what

Two new permissions, so that subcontractor details are seen only by the
people who work with them.

| What | Who |
| --- | --- |
| See the roster, a subcontractor's page and their compliance | `subcontractors.view`: Production Managers, Location Managers, Leadership and the Owner, at the subcontractor's Locations. |
| Add and edit subcontractors, move them through recruiting, record compliance, set VIP | `subcontractors.manage`: Production Managers and Location Managers, Leadership and the Owner, at those Locations. |
| Bench, unbench and offboard | The same, with a reason every time. See question 4. |
| Add a Location's extra requirements | `settings.locations.manage`, as for other Location settings. |
| Nothing | Sales, the Contact Center and Finance, in this slice. |

## Audit and Activity

Every change is an audit event saved with it: added, details changed, each
change of where they stand, VIP set or taken off, each compliance item
recorded or renewed. They are shown on the subcontractor's own page as its
history. None is in a customer's Activity.

## Tests

As slices 8 to 11 had: who may and may not, as a matrix of roles and
Locations; every refusal; nothing half-saved; history kept exactly;
compliance worked out against worked examples, expiry included; the roster
showing only the Location's own; and the migrations on MySQL 8.4, with every
new stored name checked against its column.

## Decisions needed

**Answered by Jacob on 2026-10-14 (decision 148): "I like them all. Let's
run."** The questions are kept below as they were asked.

Each has a recommendation. "Follow your recommendations" is a complete answer.

1. **Which Location a subcontractor belongs to.** *Recommended:* one or more
   Locations, chosen when they are added. The roster of a Location shows the
   ones who work there. *Alternative:* one home Location each.
2. **Recruiting and roster status as one list.** *Recommended:* one "where
   they stand" list, from Prospect through Active, Benched and Offboarded,
   plus Not hired; VIP a separate mark; Pending compliance worked out from
   the checklist rather than set. *Alternative:* recruiting stage and roster
   status kept as two separate fields.
3. **What is asked when one is added.** *Recommended:* business name and
   contact person required; phone or email at least one; crew size and
   services optional at first; home base as town and ZIP, not a full
   address. *Alternative:* everything required from the start.
4. **Who benches, unbenches and offboards.** *Recommended:* the Production
   Manager or a manager, with a reason every time, as for VIP (your answer:
   "the PM decides"). *Alternative:* managers only.
5. **Can someone who is not compliant be Active?** *Recommended:* yes, shown
   as "Pending compliance" beside Active, because work cannot be given to
   anyone in Backoffice yet. When work is given out, a later slice will
   refuse anyone pending. *Alternative:* not Active until compliant.
6. **The documents.** *Recommended:* not in this slice: record the days only.
   Storing the files needs the private storage of decision 33 (a DigitalOcean
   Space, which is a paid service you would set up) and a tested backup for
   it, which is its own step. *Alternative:* add a step 12c for documents
   once the storage is set up.
7. **The New Mexico waiver certificate.** *Recommended:* required at every
   Location that is in New Mexico, as an extra requirement on those
   Locations, so a Location elsewhere is not asked for it. *Alternative:*
   required for every subcontractor everywhere.
8. **Expiring soon.** *Recommended:* marked 30 days before it expires; no
   email. *Alternative:* another number of days.
9. **Who sees subcontractors.** *Recommended:* Production and managers only,
   with the two new permissions. Finance comes in with payouts.
10. **No message to subcontractors** in this slice. *Recommended:* none.
    "Onboarding sent" records that someone sent it.

To start, Jacob needs to approve the plan, or step 12a alone, and answer
questions 1 to 4. Questions 5 to 8 are needed before 12b.

## Known limits of this plan

- **Compliance is somebody's word** that a document was received, until the
  documents are stored.
- **No subcontractor can be given work yet.** That is the next slice after
  this one.
- **No tax ID numbers are kept,** on purpose: a W-9 is recorded as received.

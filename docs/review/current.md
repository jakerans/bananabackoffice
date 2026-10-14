# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-14 · **Branch:** `checkpoint-slice-12a-roster`

**State:** **slice 12, step 12a (subcontractors: the roster and recruiting)
is built and waits for Jacob's review. It is not merged.** The slice 12 plan,
approved as decision 148, is on this branch too and goes into `main` with it.

## What changed

Plan: [../slice-12-subcontractors-plan.md](../slice-12-subcontractors-plan.md),
"Step 12a" and "Step 12a as built".

- **Production > Subcontractors is a live page:** one Location's
  subcontractors in three parts, on the roster (Active and Benched),
  recruiting (Prospect to Onboarding sent) and past (Offboarded and Not
  hired), with a search by name.
- **Add a subcontractor:** business name and person to talk to, a phone or
  an email, and if known, crew size, home town and ZIP, and what they do.
  They start as a prospect and open on their own page.
- **Each subcontractor's page:** who they are, where they stand, the VIP
  mark, and their history. "Change where they stand" moves them; benching,
  offboarding and not hiring need a reason, and so does bringing them back.
  VIP is set or taken off with a reason, only on an Active subcontractor,
  and goes when they leave Active.
- **Who sees them:** Production Managers, Location Managers, Leadership and
  the Owner, at the subcontractor's Locations, through two new permissions.
  Sales, the Contact Center and Finance do not.
- **Stored:** a `subcontractors` table and two link tables. No tax ID number
  and no document. The history is the audit record.

## Tests and checks

- `composer test`: formatting, PHPStan and every test; the count is in the
  chat report for this push. 36 are new, in
  `tests/Feature/Subcontractors/SubcontractorsTest.php`; the role test now
  lists the two new permissions.
- They cover: adding with every detail and with the fewest; every refused
  field; a Location the person does not look after; a service no longer
  offered; changes kept only for what changed; a Location they do not look
  after staying; never deleted; recruiting steps; reasons for benching,
  offboarding, not hiring and coming back; VIP rules; who may, for eight
  kinds of person; the pages.
- Both GitHub jobs, including the migration on MySQL 8.4: see the chat
  report.

## Browser review (done by the agent, on the local site, fictitious data)

As the sample Production Manager at Northside:

1. "Subcontractors" is in the Production Center menu and opened empty.
2. Adding was refused without a person to talk to and a way to reach them;
   then "Sandia Brush Co. (review)" was added and opened as a prospect.
3. Moved to Screened, Approved and Active; made VIP with a reason; benching
   was refused without a reason, then benched, which took VIP off. The
   history listed each step with its reason.
4. The list showed it under "On the roster" as Benched, with its contact,
   crew of 4, what they do and home base.
5. The sample salesperson was refused the page.
6. At a phone's width the list fits.

## Architectural decisions made

- **The history is the audit record,** not a table of its own, as for
  Projects (decision 34).
- **The policy decides by the subcontractor's Locations:** anyone who holds
  the permission at one of them.

## Deviations from the approved plan

None. Three readings, in "Step 12a as built", for Jacob to change if wrong:

- Any standing can follow any other, so a step can be skipped or undone.
- The ordinary recruiting steps take an optional note, not a required reason.
- A service taken off Sales' list stays on a subcontractor who has it.

## Known limitations

- No compliance yet (step 12b), no documents, no portal, and no work can be
  given to a subcontractor.

## Decisions needed

1. **Merge step 12a (and the approved slice 12 plan) into `main`, and start
   step 12b, compliance?** Its questions were answered in decision 148.

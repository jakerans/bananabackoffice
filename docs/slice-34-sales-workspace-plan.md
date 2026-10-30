# Slice 34 — The Sales Workspace: Plan

Status: **proposed on 2026-10-30 after the plan check following slice 33,
and approved the same day with every recommendation, both steps built
straight through (decision 212).** Written as what the implementation agent
recommends.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) after
slice 33 (the full check is in the review handoff of 2026-10-30):

1. **5.3, the Sales Workspace, is built from the estimate to the report**
   (slices 7, 31 and 33), but there is no page for the salesperson's day:
   **Sales > Workspace** is still "Coming soon" and opens the Leads list.
2. **Discovery (WNBET) is not asked during the appointment** (5.3, "Added
   2026-10-13"): only one "customer priorities" answer in the handoff, after
   the sale (8b).
3. **Sales > Commissions is "Coming soon"** although commissions are built
   (slice 20) and each person sees their own at "My pay".

## Settled by Jacob (decision 212)

1. **The Workspace shows today first, then the next seven days.**
2. **Discovery is five short written answers, optional;** nothing blocks
   presenting or signing. They can be changed until the handoff is complete.
3. **The handoff starts filled in** from the option signed for and from
   discovery, and is still checked and saved by the salesperson.
4. **Sales > Commissions opens "My pay".**

## Steps

| Step | Delivers |
| --- | --- |
| 34a | **The salesperson's day.** Sales > Workspace > Today: each of their appointments today (run, going on or still to come) and in the next seven days, with the customer, address and time; each estimate on the Lead and where it stands (not started, not priced yet, built, presented, signed, sold, lost); whether a report is due; one tap to the estimate, the presentation or the report. Reports still due from earlier days are listed first. A manager chooses whose day to see, or everyone's at the Locations they manage. Sales > Commissions opens "My pay". |
| 34b | **Discovery and a handoff that starts filled in.** Wants, Needs, Budget, Expectations and Timeline on the Lead, written during the appointment from the Workspace or the estimate, and shown beside the estimate. After a sale the handoff opens filled in: the scope from the option signed for (its rooms, and each room's surfaces and coats) and the priorities from discovery. The salesperson checks it and completes the rest, as today. |

## Who

- **The Workspace:** anyone in Sales sees their own appointments at
  Locations where they may see Leads; a manager (`appointments.manage`) sees
  anyone's at the Locations they manage. As Sales > Calendar today.
- **Discovery:** the people who manage the Lead's estimates
  (`LeadPolicy::manageProposals`). Anyone who may see the Lead reads it.

## Not in slice 34

Confirmation calls and selfie videos (5.3, they need texting), Google
Calendar, a calendar grid, taking the deposit in the home (22), offline use.

## Step 34a as built

- **Sales > Workspace > Today** (`/sales/workspace`, route
  `sales.workspace`; the Workspace now opens on it, with Leads beside it):
  - **today's appointments**, whether run, missed, going on or still to come,
    then the next seven days' that still stand, each day headed Today,
    Tomorrow or its date in the appointment's own timezone;
  - each with its time, where it stands (Upcoming, Going on now, Report due,
    Run, No-show, "Signed. Finish the report"), the customer, linked to the
    Lead, and the address;
  - each estimate on the Lead with its price or range and where it stands
    (Not priced yet, Built, not presented, Presented, pending, Signed or Sold
    for an option, Lost), with **Open** and, while it can still be sold,
    **Present**; "No estimate yet" links to the Lead to start one;
  - **After-action report** or **Finish the report** wherever one is due,
    and the reports still due from earlier days listed first.
- **Whose day:** a salesperson sees their own, at Locations where they may
  see Leads. A manager chooses Mine, Everyone at my Locations, or any
  salesperson with appointments there, as on Sales > Calendar.
- **Sales > Commissions** is live and opens "My pay".

**Known limits:** the shared top bar is still wider than a phone screen, as
on every page; the Workspace's own content fits.

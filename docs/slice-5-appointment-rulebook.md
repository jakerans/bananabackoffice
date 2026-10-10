# Slice 5: Appointment Issuance and Ownership Handoff Rulebook

Status: **design only.** These are Jacob's decisions from conversation on
2026-10-08, recorded by planning job #13 as decisions 75 to 84 in
[decisions.md](decisions.md).

- **Product direction:** the rules below are `LOCKED` unless a line says
  `CONFIGURABLE` (an adjustable setting) or `PENDING` (still open).
- **Implementation:** nothing is proposed, approved or built. This rulebook
  creates no application code, table, permission, role grant, screen, navigation
  item, status in the code, email or setting. Projects are not implemented.
  Building any of it needs its own reviewed plan and Jacob's explicit approval.

**Later appointment work must follow this rulebook.** Where a later plan needs
something this rulebook does not say, it asks Jacob; it does not guess.

## Words used here

| Word | Meaning |
| --- | --- |
| **Booking** | Saving an appointment for a Lead with a salesperson, a date and time, and a length. |
| **Issued** | The handoff from Contact Center to Sales. It happens the moment the appointment is saved (rule 3). This is the architecture's "Lead Issued" ([product-architecture.md](product-architecture.md), 5.1). |
| **Salesperson** | A staff member with Sales Consultant access at the Lead's Location. |
| **A manager** | Owner, Leadership or Location Manager, with access at the Lead's Location. **Not** a Call Center Agent. This is how the documents already use the word (decision 52; "a manager there" in [checkpoint-b.md](checkpoint-b.md)). Confirmed by Jacob on 2026-10-09 (decision 88). |
| **Contact Center** | The call center's side of the work, including the slice 4 call queue. |
| **Re-issue** | A new booking, made by the call center, of a Lead that was released back to it after having been issued before. |

## The rules

### 1. When a Lead can be booked

A Lead can be booked only when it is all of:

- **Qualified** (the existing B7 milestone, unchanged);
- **routed to a Location;** and
- **linked to a customer with a property address.**

A booking needs **a salesperson, a date and time, and a length.** The length
defaults to **90 minutes**.

### 2. Who can book

- **Owner, Leadership, Location Manager and Call Center Agent.**
- **A Sales Consultant, only for a Lead already assigned to them.**

The chosen salesperson must have **Sales Consultant access at the Lead's
Location.** Everything is still checked where the Lead is: Location stays the
authorization boundary and being assigned grants nothing
([permission-model.md](permission-model.md), rules 1 and 6).

### 3. "Issued" happens the moment the appointment is saved

In one step, when the appointment is saved:

1. the Lead is **assigned to the salesperson**;
2. the Lead gets a new status, **Appointment Booked**;
3. the Lead **leaves the call queue**;
4. the **issued time is stored permanently**, so Leads Issued can be counted;
5. an **Activity entry** is written; and
6. the **salesperson is notified**.

Appointment Booked is a new Lead status. It does not change what Qualified means
(decision 55): Qualified is still the milestone only, and it is what allows a
booking.

**For the slice 4 call queue:** once appointments are built, Appointment Booked
is one of the things that removes a Lead from the call queue, alongside the
exits in decision 67. (Built in slice 6, step 6a, on 2026-10-10.)

### 4. Notification

The salesperson is told **in the app**, and by **an email that carries a link
and no customer details.**

### 5. No double booking inside Backoffice

Backoffice **refuses** to book a salesperson into a time that overlaps another
Backoffice appointment of theirs. Conflicts with a personal calendar are not
checked: they **wait for Google Calendar**, which the architecture names as the
external conflict source (5.4).

### 6. Reschedule

**The salesperson or a manager** changes the time. The salesperson stays the
same, the Lead is still issued, and it **counts as one issued Lead.** The old
time is kept in the history.

### 7. Moving an appointment to a different salesperson

**Only a manager** can do this. It is a **handoff**: an Activity entry is
written, the new salesperson is notified, and the history shows **both people**.

### 8. The customer cancels

- The appointment is **kept as a cancelled record**, with **who cancelled** and
  **a reason**.
- The Lead **stays with the salesperson**, who is expected to rebook it, and it
  **still counts as issued.**
- If it is **not rebooked within 30 days of the cancellation**, it comes off the
  salesperson's list **automatically** and goes to the call center in a group of
  its own, **Cancelled Appointments.** The 30 days is `CONFIGURABLE`: an
  adjustable setting.
- **Cancelled Appointments is a saved segment.** It can be selected on its own
  for the call queue. Once broadcasts exist, it can also be selected for
  reactivation texts and emails, under the consent, suppression and send-window
  safeguards already in [product-architecture.md](product-architecture.md)
  (4.5, "Broadcast safeguards"). No broadcast, text or email to customers is
  designed or authorized here.
- If the call center rebooks one, it **may go to any salesperson** (rule 2 still
  decides who is eligible) and is recorded as a **re-issue with full history.**

### 9. A manager sends an issued Lead back to Contact Center

A manager can send **any issued Lead** back to Contact Center **by hand, with a
note.** It **re-enters the call queue.**

### 10. Booked in error

A manager can mark an appointment **"booked in error", with a note.** The
handoff is **undone**, the Lead **returns to Contact Center**, and it **does not
count as issued.** This is **manager-only and recorded.**

### 11. Cancellation reasons

Cancellation reasons are a **managed list on the existing Lead & Sales Lists
page.** This rulebook **reserves the list.** The reasons themselves come from
Sales Leadership and are `PENDING`.

This is a newer decision than the part of decision 39 that fixed the managed
lists at five. The existing rules for managed lists (decision 30) apply to it.
The list is not created until appointment work is approved.

### 12. A salesperson picks a released Lead back up

When a Lead was **released from a salesperson to the call center** (after a
cancellation under rule 8, or an expired Pending under rule 14) and the
homeowner contacts that salesperson, for example two months after a bid, the
salesperson **can take it back with a short note** and continue where it left
off:

- **same history, same bid;**
- **no new issued count;**
- it **leaves the call center groups;** and
- an **Activity entry** is written.

If it has **already been rebooked with a different salesperson, a manager must
make the move** (rule 7).

This is a narrow exception to decision 44 ("Sales Consultants do not claim
Leads"). It covers only a Lead that this same salesperson was issued before.
A Sales Consultant otherwise cannot take an unassigned Lead or a colleague's Lead.

### 13. A Project is created when the sale is Won

**Before Won, the record is the Lead and its appointment.** A Project is created
when the sale is Won.

This is the **target rule**. It replaces:

- the **Project-from-Qualified** assumption in decision 54 (and the same idea in
  decision 27, "a Qualified Lead may produce one or more Projects"); and
- the **historical B8 sequence** ("Projects from a Qualified Lead or directly")
  in [checkpoint-b.md](checkpoint-b.md) and [roadmap.md](roadmap.md).

Nothing is built. Decision 54 and the B8 to B12 sequence stay in the record as
history and give no authority.

Historical permission rows this affects, in
[permission-model.md](permission-model.md), "Current and historical planned
permissions" and its matrix. None of them is in the code, and none is rewritten
by this rulebook:

| Historical row | Effect |
| --- | --- |
| `leads.convert`, "Create a Project from a Qualified Lead" (and the matrix row of the same name) | Contradicted by the target rule. Must not be built as written. |
| `projects.create`, "Create a Project, from a Lead or directly" (and the matrix row "Create a Project directly") | The "from a Lead" part no longer happens at Qualified. Creating a Project directly, with no Lead, is not decided by this rulebook. |
| `projects.sales_outcome`, "Mark a Project Won (with its Sold Amount) or Lost" (and the matrix row "Mark a Project Won or Lost") | A Project does not exist before Won, so where Pending and Lost are recorded must be revalidated with the after-action report. |

Also resting on the old assumption, and to be revalidated before any Project
work: decision 54's points on who creates a Project from a Lead, the first Sales
Consultant responsibility, a sales outcome that starts as Open, and "once a Lead
has produced any Project it stays Qualified for good"; and decision 28's
"Open, Won or Lost" as a Project's sales outcome.

### 14. Pending ownership

The salesperson **owns a Pending for an adjustable period, starting at 30
days.** After that it **releases to Contact Center Rehash.**

**Provisional until Sales Leadership confirms.** The period is `CONFIGURABLE`;
the policy itself is still `PENDING` in the architecture (5.5).

**Offer Valid Until is a separate concept and stays open.**

## What is counted as issued

| Event | Leads Issued |
| --- | --- |
| Appointment saved (rule 3) | Counts. The issued time is kept permanently. |
| Reschedule, same salesperson (rule 6) | Still one issued Lead. |
| Customer cancels (rule 8) | Still counts as issued. |
| Booked in error (rule 10) | Does not count as issued. |
| Salesperson picks a released Lead back up (rule 12) | No new issued count. |
| Moved to a different salesperson (rule 7), or re-issued by the call center (rule 8) | Recorded with full history. **Which salesperson it counts for is open** (Sales Leadership). |

## Still open

Recorded as open. Nothing here may be assumed.

1. **Which salesperson an issued Lead counts for** after a reassignment or a
   re-issue. Sales Leadership.
2. **Offer validity** (Offer Valid Until). Sales Leadership.
3. **The cancellation reasons themselves.** Sales Leadership.
4. **No-shows and appointment outcomes.** These belong to the later after-action
   report (architecture 5.6).
5. **The Pending ownership period** is provisional (rule 14) until Sales
   Leadership confirms it.

## Readings confirmed

Two places where Jacob's wording was read against the existing documents. **Jacob
confirmed both on 2026-10-09 (decisions 88 and 89).** They are rules now.

- **"A manager"** in rules 6, 7, 9, 10 and 12 means Owner, Leadership or
  Location Manager at the Lead's Location, and not a Call Center Agent. Rule 2
  names the Call Center Agent separately for booking, and the documents already
  use "manager" this way. (Slice 4's "manager summary" is a different case:
  decision 71 gave it to "people who may assign Leads", which includes agents;
  decision 85 now puts that behind a switch for agents.)
  One consequence: a Call Center Agent can book, and can rebook a Lead in
  Cancelled Appointments, but cannot change the time of a salesperson's existing
  appointment.
- **A Sales Consultant who books their own Lead books it for themselves.**
  Choosing a colleague would be handing a Lead out, which decision 44 keeps with
  management and the call center.

## Not decided here: for the appointment build plan to bring to Jacob

These are gaps the fourteen decisions do not cover. They are listed so the build
plan asks instead of guessing. None is a rule.

- **Which status a Lead shows, and who (if anyone) it is assigned to, after it
  goes back to Contact Center** under rules 8, 9, 10 and 14.
- **How a returned Lead is called:** where it restarts in the slice 4 cadence,
  and how the Cancelled Appointments and Rehash groups sit beside today's single
  shared queue.
- **What happens to an appointment when its Lead is moved to another Location,
  returned to central or disqualified, or when its salesperson loses access.**
  Today a move always clears the assignee (decision 44) and lost access
  unassigns the Lead (permission-model.md, rule 9).
- **A cancellation that does not come from the customer** (the salesperson or
  the company cancels). Rule 8 covers the customer cancelling.
- **Whether rebooking by the same salesperson after a cancellation** changes the
  count. Rule 8 says only that the Lead still counts as issued.
- **Which time zone an appointment time is shown in.** Each Location has its own
  (decision 68).
- **How the in-app notification works.** Backoffice has none today.
- **Names of permissions, tables, screens and settings pages.** None is chosen.

## What this rulebook does not do

- It builds nothing and authorizes nothing to be built.
- It adds no permission and changes no role. The roles named in rule 2 and the
  word "manager" describe who the future feature is for; the permissions that
  carry them are designed in the build plan and reviewed there.
- It does not implement Projects, the Sales Workspace, the Calendar, Google
  Calendar, the booking engine, saved segments, broadcasts or the after-action
  report.
- It sends nothing to anyone.

# Slice 7 — What Happened at the Appointment, Proposals and Their Results: Proposed Plan

Status: **approved in four steps on 2026-10-12 (decision 125), each stopping
for Jacob's review. Jacob agreed to every recommendation under "Decisions
needed". Step 7a is built (2026-10-12) and waits for his review and his word
to merge; see "Step 7a as built". 7b to 7d are approved in order and not
started.**

This is the after-action report of
[product-architecture.md](product-architecture.md), section 5.6, read with
Jacob's decisions 121, 123 and 124 in [decisions.md](decisions.md). It follows
the slice 5 rulebook
([slice-5-appointment-rulebook.md](slice-5-appointment-rulebook.md)) and builds
on slice 6 ([slice-6-appointments-plan.md](slice-6-appointments-plan.md)).

## Why this is next, from the plan

Jacob asked on 2026-10-11 not to be asked whether the after-action report is
next, but to be told from the plan (decision 124). It is, for these reasons:

1. **The plan's own order.** Section 20 gives the path of a customer: Contact
   Center, appointment booked, then the salesperson's "issued lead/calendar →
   confirmation → appointment → Pending/Rehash/Won/Lost". Slices 4 to 6 built
   it as far as the calendar. The next two words are "confirmation" and
   "appointment".
2. **Confirmation cannot be built yet.** The confirmation process is open with
   Sales Leadership (plan, 24). Nothing about it is decided.
3. **So the next step that can be built is the appointment and its result,**
   which is section 5.6. It is also the first thing everything after it reads
   from: Pending and Rehash (5.5), a Project when a sale is won (rulebook rule
   13), commission (5.7), and every Sales figure in 5.2 including NSLI.
4. **What else could have been next, and why not.** The booking engine (12)
   needs Google Calendar and a way to measure travel, which the plan itself
   defers. The call center's new-Lead popup, snooze tray and Aged Leads (4.2)
   could be built now; they improve something that works, where this slice
   supplies something missing, and Jacob can move them forward if he prefers.

**It cannot start with the report's form.** Decision 124 says only a proposal
has a result, and "you can't resolve a proposal that hasn't been created".
Backoffice has no proposal record. So the slice is built in the order the
facts depend on each other, and the report is the short form that ties them
together.

## What slice 7 is, and is not

Four reviewed steps. Each stops for Jacob's review.

| Step | Delivers | From |
| --- | --- | --- |
| 7a | Say what happened to an appointment whose time has come: **run**, or **no-show**. A list of appointments still waiting for that answer. | 5.6; decisions 121 and 123 |
| 7b | **Proposals:** a simple record of what was pitched and for how much, tied to the appointment or appointments it was presented at. **Demo Rate** can then be counted. | 5.3, 5.6; decision 124 |
| 7c | **A proposal's result:** Pending, Sold or Lost, with the reason and the objection. The after-action report as one short form that does 7a to 7c from the car. | 5.6 |
| 7d | **The first Sales figures:** Leads Issued, appointments run, Demo Rate, Close Rate and NSLI. | 5.2 |

Not in slice 7, each needing its own plan and approval:

- **A Project when a sale is Sold** (rulebook rule 13) and the handoff to
  Production.
- **Pending ownership running out, and Rehash** (rule 14; plan 5.5).
- **Commission** (5.7) and the Leaderboard (5.8).
- **The rest of the selling flow in 5.3:** discovery, measurements, the pricing
  engine, options, the presentation, the agreement and the deposit. The
  proposal built here is the plain record those will later fill in.
- **The voice note and its transcript** (5.6). See question 11.
- **Confirmation, Google Calendar and the booking engine.**
- **Any message to a customer.**

## Step 7a — Run, or no-show

### What a person sees

**On the Lead's Appointment card,** once the appointment's start time has
come: two buttons, **Appointment was run** and **No-show**, each with an
optional note. Today that card says "The time of this appointment has passed."
and nothing more.

- **Run:** the card shows it as run, by whom and when. The Lead stays with the
  salesperson. What the Lead shows is question 3.
- **No-show:** the card shows it as a no-show. What happens to the Lead is
  question 2.
- Either can be corrected by a manager, with a note, because a wrong answer
  changes the figures.

**In Sales > Calendar:** a new list at the top, **To close out**: appointments
whose time has come and that have no answer yet, oldest first. The plan says
an appointment "remains operationally incomplete until report is submitted";
this is where that shows. A salesperson sees their own; a manager sees every
one at their Locations.

**On the customer page:** "Time has passed" becomes Run or No-show once
answered.

### What it stores

Two new appointment states, run and no-show, with who said so, when, and the
note. No new table. Cancelled, rescheduled and "booked in error" stay as they
are (slice 6).

### Step 7a as built (2026-10-12)

**Merged into `main` on 2026-10-12** with Jacob's word ("Merge it and
continue."), and on staging.

Built as planned above. What a reader of the code should know:

- **The actions** are `CloseOutAppointment` and `CorrectAppointmentCloseOut`
  in `app/Actions/Appointments/`. Both go through `ChangeLeadLifecycle`, as
  every appointment action does.
- **Two new appointment states,** run and no-show, on the existing
  `appointments` row. **No migration:** every column they use already exists
  and is wide enough.
- **One new Lead status,** Appointment run. A no-show Lead takes the existing
  Appointment cancelled status, so the 14-day wait, the "To rebook" list, the
  release and the Cancelled Appointments queue from slice 6 all apply to it
  without being built again. The appointment itself says No-show, and so does
  the notice the call center reads.
- **Who may answer** is `LeadPolicy::closeOutAppointment`: the same people who
  may reschedule.
- **The screens:** the Appointment card asks "What happened?" once the start
  time has come; Sales > Calendar has "To close out" at the top; the customer
  page shows Run or No-show.

Readings made while building, none of them a new rule:

- **A no-show Lead reads "Appointment cancelled"** in lists, as any Lead
  waiting to be booked again does. It was not given a status of its own.
- **An appointment can be answered from its start time, and can still be
  cancelled or rescheduled until its end time.** For the length of the
  appointment both are offered.
- **Only an appointment whose Lead is still "Appointment booked" can be
  answered.** Since decision 126 a Lead cannot leave Sales with an unanswered
  appointment that is over, so this no longer leaves one unanswered for good.
- **When an earlier appointment is answered while a later one stands,** the
  Lead stays Appointment booked, because it has an appointment to come. Since
  decision 126 this can only be a record from before it.
- **A correction changes the Lead only if nothing has happened to it since.**
  Otherwise only the appointment is corrected.
- **"Booked in error" is no longer offered once an appointment is run or a
  no-show.** A manager uses Send back, which asks whether it should ever have
  been booked.

### Added to step 7a on 2026-10-12 (decision 126)

Jacob's reply to the first handoff: close out before anything else, a prompt,
a reminder 15 minutes after the appointment, and the salesperson told when a
manager touches their appointment.

- **Close out first.** `Lead::hasAppointmentToCloseOut()` is true while the
  Lead has a standing appointment whose end time has passed. `BookAppointment`,
  `SendLeadBackToContactCenter`, `RouteLead`, `ReturnLeadToCentral` and
  `DisqualifyLead` each refuse while it is. The card stops offering "Book
  another appointment" and "Send back to Contact Center" until the answer is
  given, and the Lead page says why instead of opening a dialog.
- **In order.** `CloseOutAppointment` refuses a salesperson's later appointment
  while an earlier one of theirs is over and unanswered. Not a manager's
  answer.
- **The prompt** is the component `pages::sales.close-out-prompt`, in the
  layout under the header. It counts only the signed-in person's own
  appointments and names no customer.
- **The reminder** is `RemindToCloseOutAppointment`, run by the timer that
  already runs every minute (`RunQueueTick`, counted as `reminded`). One new
  column, `appointments.close_out_reminded_at`, keeps it to one for each
  appointment. The notice is saved with that mark; the email goes after and a
  failure to send is reported, not retried.
- **Told when a manager touches it:** `AppointmentChanged` has five new kinds:
  the reminder, marked run, marked no-show, corrected, booked in error.

## Step 7b — Proposals

### What a person sees

**On the Lead page,** a new card, **Proposals**:

- **Add proposal**, for the salesperson the Lead is with, or a manager: a short
  name for what was pitched ("Whole exterior", "Cabinets only"), the price,
  and which of the Lead's run appointments it was presented at. It can be
  added on the day or later.
- Each proposal shows its name, price, the appointment or appointments it was
  presented at, who added it and when.
- A proposal can be tied to a second appointment later, for a return visit.

**Demo Rate** follows from this exactly as decision 124 says: a run
appointment with no proposal counts against it, and counts for it from the
moment a proposal is added, even a day later.

### What it stores

| Table | Holds |
| --- | --- |
| `proposals` (new) | One row per proposal: the Lead, its Location, customer and property; the name; the price; who added it and when. Never deleted. |
| `appointment_proposal` (new) | Which proposals were presented at which appointments. One proposal can have several appointments and one appointment several proposals (decision 121). |

### Step 7b as built (2026-10-12)

Built as planned above. What a reader of the code should know:

- **The actions** are `AddProposal` and `PresentProposalAgain` in
  `app/Actions/Proposals/`. Both go through `ChangeLeadLifecycle` so the Lead
  cannot change underneath them, and both leave the Lead exactly as it was:
  same status, same person, same revision.
- **Two new tables,** `proposals` and `appointment_proposal`, as above. A
  proposal cannot be deleted.
- **The price is kept in cents** (decision 127). `App\Support\Money` reads
  what a person types and shows it.
- **Who may add** is `LeadPolicy::manageProposals`: a manager where the Lead
  is, or the salesperson the Lead is with while it is with Sales. Everyone who
  may see the Lead sees its proposals, the Contact Center included (plan,
  11.4).
- **The card** is `pages::leads.proposals`, on the Lead page under the
  Appointment card. It shows nothing until the Lead has an appointment that
  was run, and appears the moment one is marked run, without a reload.
- **Two audit events,** `lead.proposal_added` and
  `lead.proposal_presented_again`, both in the customer's Activity.

Readings made while building, none of them a new rule:

- **Every proposal has a name, a price and at least one run appointment,** so
  every saved proposal counts for Demo Rate (decision 125, answers 4 and 5).
  There is no half-finished proposal.
- **A run appointment with no proposal says so on the card:** "was run and
  has no proposal yet. Until one is added it counts as run with nothing
  presented." This is the no-demo of decision 124, shown where it can be
  fixed.
- **Once a Lead has left its salesperson, only a manager can still add** what
  was pitched at an appointment that was run. The salesperson can while the
  Lead is theirs.
- **The salesperson is told** in the app when a manager records a proposal at
  their appointment, following decision 126.
- **A proposal cannot be edited or removed.** A wrong name or price stays
  until step 7c decides how a proposal is corrected. See the handoff.

## Step 7c — Results, and the report

### What a person sees

**On each proposal:** it starts **Pending**. The salesperson the Lead is with,
or a manager, marks it:

- **Sold:** with the sold amount, which starts at the proposal's price and can
  be changed (decision 36).
- **Lost:** with a reason from the Sales Loss Reasons list that already exists
  on Lead & Sales Lists, and the primary objection.
- **Still Pending:** with the main objection, so the reason it is open is
  written down.

**The after-action report** is one short form opened from the Appointment card
or from "To close out", built to take a minute or two on a phone:

1. Run, or no-show.
2. If run: was anything presented? If yes, the proposal or proposals, each with
   its price. If no, the report is done, and the appointment counts as run
   with no proposal until one is added.
3. For each proposal: Pending, Sold or Lost, with what each needs.
4. Whether a return visit is booked. Backoffice checks this itself and shows
   it, as the plan asks ("system check whether rehash booked").
5. Short notes.

Everything in the report can also be done one piece at a time from the Lead
page. The report writes the same records; it is not a second copy.

### What it stores

On `proposals`: the result, the sold amount and when, the loss reason, the
objection, the note, and who recorded each. A new managed list, **Objections**
(question 8). New Lead statuses as question 3 and question 9 decide.

## Step 7d — The first Sales figures

**Sales > Performance** (today a placeholder) shows, for a chosen month, for
one salesperson or a Location:

| Figure | Counted as |
| --- | --- |
| Leads Issued | Issues that still count (slice 6). |
| Appointments run | Appointments marked run. |
| Demo Rate | Run appointments with at least one proposal, over run appointments (decision 124). |
| Close Rate | Question 10. Whatever is chosen, several proposals from one appointment count once, and two accepted count as one (decision 124). |
| Sold Revenue | The sold amounts. |
| NSLI | Sold Revenue over Leads Issued. The plan's primary coaching figure (5.2). |
| Cancellation Rate | Cancelled appointments over appointments booked. |

A salesperson sees their own. A manager sees their Locations'. No targets, no
ranking, no Leaderboard.

## Who may do what

No new permission is proposed. All checked at the Lead's Location.

| Action | Who |
| --- | --- |
| Mark run or no-show; add a proposal; mark its result; submit the report | The salesperson the Lead is with; a manager (`appointments.manage`) |
| Correct an answer already given | A manager, with a note |
| See proposals and results | Anyone who may see the Lead. The plan lets the Contact Center see the sales outcome and the sold amount (11.4). |
| See the figures | A salesperson their own; a manager their Locations' |

## Audit and Activity

Each of these is an audit event saved with its change and appears in the
customer's Activity: appointment run; no-show; proposal added; proposal Sold,
Lost or still Pending; a correction.

## Tests

Feature tests for each step as slice 6 had: who may and may not, as a matrix
of roles and Locations; every refusal; that nothing is half-saved; the lists
showing only what a person may see; Demo Rate and Close Rate against worked
examples, including a proposal added a day late, three proposals with one
sold, and two sold from one appointment; two people answering the same
appointment at the same instant (real processes); and the migrations on MySQL
8.4, with every new stored name checked against the length of its column.

## Decisions needed

**Jacob agreed to every recommendation on 2026-10-12 (decision 125).** On
question 10 he confirmed it is counted for each Lead, and added that two
proposals sold against one Lead count once unless the customer is booked again
later for a second job.

1. **Who says an appointment was run or a no-show, and from when?**
   *Recommended:* the salesperson whose appointment it is, or a manager, from
   the appointment's start time.
2. **What happens to a Lead after a no-show?** *Recommended:* the same as a
   cancellation by the customer (rulebook rule 8): it stays with the
   salesperson for the 14 days to be booked again, then goes to the Cancelled
   Appointments queue, where the card says it was a no-show. It still counts as
   issued.
3. **What does a Lead show once its appointment has been run?** *Recommended:*
   a new status, **Appointment run**, still with the salesperson, until a
   proposal is Sold or the Lead is marked lost (question 9).
4. **What makes a proposal count for Demo Rate?** The plan says "draft estimate
   alone is not enough". *Recommended:* a proposal that is saved with a name
   and a price and tied to that appointment. One started without a price does
   not count yet.
5. **Can a proposal exist with no appointment,** for example a quote sent
   after a phone call? *Recommended:* not in this slice. Every proposal is tied
   to at least one run appointment.
6. **Sold.** *Recommended:* it records the sold amount and the day, and the
   Lead shows **Sold**. Creating the Project is a later slice (rule 13). The
   Lead's other proposals stay as they are until someone marks them; they do
   not count against Close Rate either way (decision 124).
7. **Lost.** *Recommended:* the reason comes from the Sales Loss Reasons list
   as it stands today (nine reasons; "Chose Another Contractor" asks which
   one; "Other" needs a note).
8. **Objections.** The list is open with Sales Leadership (plan, 24).
   *Recommended:* a new managed list, Objections, that starts with "Other (see
   note)" so the step works on its first day, unless Jacob gives a starting
   list as he did for cancellation reasons.
9. **When is the Lead itself finished?** *Recommended:* Sold when one of its
   proposals is Sold. Lost when the salesperson or a manager marks the Lead
   lost, which marks every proposal still Pending as Lost with the same
   reason. Until then it stays with Sales as Appointment run.
10. **Close Rate when a sale takes two visits.** Decision 124 counts wins
    against appointments. *Recommended:* count each Lead's run appointments as
    one, so a return visit that closes the sale does not lower the rate: Leads
    with a Sold proposal, over Leads with at least one run appointment.
    *Alternative:* every run appointment counts, so one sale after two visits
    is one win in two.
11. **The voice note and its transcript.** *Recommended:* not in this slice. It
    needs somewhere private to keep audio and an outside service to
    transcribe it, each of which needs Jacob's approval on its own.

Still open with Sales Leadership and not needed to start: the confirmation
process; the Pending ownership period; proposal validity; which salesperson an
issued Lead counts for after a move or a re-issue.

## Known limits of this plan

- A proposal here is a name and a price. It has no options, no pricing math
  and no document; those are the rest of 5.3.
- Sold does not create a Project, so nothing reaches Production yet.
- Pending has no clock in this slice. A proposal can stay Pending for good.
- The figures in 7d are counted from what people record. An appointment
  nobody closes out is in none of them, which is why 7a's "To close out" list
  comes first, and why decision 126 stops the Lead until it is answered.

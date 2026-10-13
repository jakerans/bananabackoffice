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
- **A proposal cannot be edited or removed in this step.** Jacob decided how
  one is corrected when he approved the merge (decision 128); it is built
  with step 7c.

**Merged into `main` on 2026-10-12** with Jacob's word ("merge it").

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

**Correcting a proposal** (decision 128, added 2026-10-12; built in step 7c):

- **Edit:** the salesperson the Lead is with, or a manager, can change the
  name and price while the proposal is still Pending. Each change is kept in
  the audit record.
- **Added in error:** a manager marks a proposal "added in error" with a note.
  It stays on the card, shown as such, and counts for nothing from then on. An
  appointment whose only proposal was added in error is again run with
  nothing presented.
- Nothing is deleted.

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

### Step 7c as built (2026-10-13)

Built as planned above, with decision 128. What a reader of the code should
know:

- **The actions,** all in `app/Actions/Proposals/`: `RecordProposalResult`
  (sold, lost, still pending), `CorrectProposalResult`, `EditProposal`,
  `MarkProposalAddedInError`, `MarkLeadLost`, `UndoLeadLost`, and
  `SubmitAfterActionReport`. Each goes through `ChangeLeadLifecycle`.
- **The report writes nothing of its own.** `SubmitAfterActionReport` claims
  the Lead once and calls the same rules the buttons use
  (`CloseOutAppointment::apply`, `AddProposal::apply`,
  `PresentProposalAgain::apply`, `RecordProposalResult::apply`) inside that one
  save, so it is saved whole or not at all and a refusal names the proposal it
  is about.
- **On `proposals`:** `result` (pending, sold, lost) with who and when;
  `sold_cents`; the loss reason and competitor; the objection; the note; and
  the "added in error" mark. **On `leads`:** why it was lost, and by whom.
  **New table:** `objections`, a managed list on Lead & Sales Lists, with
  its starting list (decision 129).
- **Two new Lead statuses,** Sold and Lost. Both are finished: not active, not
  "with Sales". They stay on the salesperson's record.
- **Who may:** `LeadPolicy::manageProposals` for results, changing a pending
  proposal and marking the Lead lost; `appointments.manage` for a correction,
  "added in error" and undoing a lost Lead. No permission was added.
- **The screens:** the Proposals card (`pages::leads.proposals`) carries the
  results and corrections; the report is its own page, `pages::leads.report`
  at `/leads/{lead}/appointments/{appointment}/report`, reached from the
  Appointment card, each "To close out" row in the Calendar, and the prompt.
- **Eight audit events:** `lead.proposal_sold`, `lead.proposal_lost`,
  `lead.proposal_still_pending`, `lead.proposal_result_corrected`,
  `lead.proposal_edited`, `lead.proposal_added_in_error`, `lead.marked_lost`,
  `lead.lost_undone`. All but the edit are in the customer's Activity.

Readings made while building, for Jacob to change if wrong:

- **A Sold or Lost Lead is finished.** It cannot be booked, sent back, moved or
  disqualified, and it leaves the lists of open Leads. It stays assigned to its
  salesperson, for the record and the figures. Only a manager's correction
  brings it back.
- **One lost proposal does not make the Lead lost.** The Lead is lost when
  someone marks the Lead lost (decision 125, answer 9). A Lead can be lost with
  nothing presented.
- **A sale is refused while the Lead still has an appointment booked.** The
  appointment is answered or cancelled first.
- **A result is given once.** After that only a manager changes it, by
  putting the proposal back to Pending with a note; it is then answered again.
  If it was the Lead's only sale, the Lead goes back to Appointment run.
- **Undoing a lost Lead** puts back to Pending exactly the proposals that
  marking it lost had turned Lost. One lost before that stays lost.
- **"Added in error" is for a Pending proposal** and cannot itself be undone.
  A Sold or Lost one has its result corrected first.
- **The competitor is optional** ("if known"), and kept only for a reason that
  asks for one.
- **Still pending needs the main objection.** The list starts with seven
  (decision 129): Price, Wants to think it over, Needs to talk to spouse or
  partner, Getting other bids, Timing, Financing, and Other (see note), which
  alone needs a typed note. Owner and Leadership change it on Lead & Sales
  Lists.

**Merged into `main` on 2026-10-13** with Jacob's word ("go ahead and merge and
use your suggestions").
- **The sold day is the day it is recorded.** There is no separate "sold on"
  date.
- **The return-visit check is on the Lead,** not in the report. Decision 126
  means a return visit cannot be booked before the appointment is answered, so
  at report time there is never one to find. Once the report is saved the
  Proposals card says "No return visit is booked for what is still pending."
  or names the one that is.
- **The two quick buttons stay** beside the report: "Appointment was run" and
  "No-show" answer step 1 only.
- **The salesperson is told** in the app when a manager gives a result,
  changes or marks a proposal, marks the Lead lost or undoes that (decision
  126). One notice covers them all.

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

### Step 7d as built (2026-10-13)

**Merged into `main` on 2026-10-13** with Jacob's word ("Merge it, and let's
continue based on the plan."). Slice 7 is complete. He did not change any of
the readings below.

Built as planned above. What a reader of the code should know:

- **Nothing is stored.** `App\Support\Sales\SalesFigures::for($location,
  $month, $salespersonId)` counts every figure from the records of slices 6
  and 7 each time the page is opened. There is no table of totals to fall out
  of step, and no migration in this step.
- **The page** is `pages::sales.performance` at `/sales/performance-board`,
  the address and menu item the placeholder had. It is opened with
  `sales.access`, like the Calendar.
- **Who sees what** is decided on the server, never by what the browser asks
  for. The Locations offered are the ones where the person manages
  appointments or is a Sales Consultant. A manager there may choose any
  salesperson or everyone; anyone else is shown their own figures whatever is
  asked for. A Location or month that is not offered falls back to the
  person's own Location and this month.
- **The month is the Location's own calendar month,** and the last twelve can
  be chosen.
- **Each figure says how it was reached** ("2 of 3 run appointments have a
  proposal"), and the page ends with how every figure is counted.

Readings made while building, for Jacob to change if wrong:

- **Leads Issued** are counted in the month the appointment was saved.
  **Appointments** (run, cancelled, no-show, booked) in the month of the
  appointment's day. **Sold Revenue** in the month the sale was recorded.
- **Close Rate** places each Lead once, in the month of its first run
  appointment: Leads first run that month that are Sold now, over Leads first
  run that month. A return visit never counts a Lead twice. A past month's
  rate goes up when one of its Leads is sold later.
- **NSLI** is this month's Sold Revenue over this month's Leads Issued. A sale
  this month of a Lead issued last month is in this month's revenue.
- **Cancellation Rate** is cancelled over booked. A no-show is shown beside it
  and not counted in it. "Booked in error" is in neither.
- **Whose figure it is,** provisional: a Lead issued counts for the salesperson
  it was issued to, an appointment for the salesperson it is with, a sale for
  the salesperson the Lead is with. The plan leaves this open with Sales
  Leadership (5.1). For a Location as a whole nothing is lost or counted
  twice.
- **One Location at a time.** There is no figure across all Locations.
- **A rate with nothing to divide by shows a dash,** not 0%.

### Changed after slice 8 (2026-10-13, decisions 133 and 134)

**Merged into `main` on 2026-10-13** with Jacob's word ("merge it", decision
135). Where this differs from "Step 7d as built" above, this is what the code
does now. Decision 135 also changes what Close Rate is divided by, from the
Leads first run in the month to the Leads first demoed in it; see "Close
Rate is demo to close" below.

- **A record of sales.** Table `sale_entries`: one line when a proposal is
  marked Sold, and one more, for the same amount below zero, when a manager
  corrects that sale. A line is never changed or deleted
  (`App\Models\SaleEntry` refuses both). Each carries the Location, the
  salesperson who had the Lead at the sale, who recorded it, when, and
  whether it is the Lead's win. Written only by
  `App\Actions\Proposals\RecordSaleEntry`, inside the same save as the sale
  or the correction. Sales made before the table existed are written into it
  by its migration.
- **Close Rate works off lead ownership (decision 134).** A win belongs to
  the salesperson who had the Lead when the proposal was accepted, whoever
  typed it in, and counts in the month it was accepted. One Lead is one win:
  a second proposal sold later adds revenue, not a win. The wins were divided
  by the Leads whose first appointment was run in the month; decision 135
  changed that to the Leads first demoed, below. A sale closed on a return
  visit the month after is a win in the later month, and a month can read
  above 100%.
- **Corrections are adjustments (decision 133).** A sale corrected in a later
  month stays in the month it was made; the amount, and the win if it was the
  Lead's only sale, come off the month it was corrected in, shown as "taken
  back by a correction". The same for a Lead Issued that is taken back later
  (booked in error, or should never have been booked): it comes off the month
  it was taken back in. A month that has passed reads the same for good.
  Sold Revenue, Leads Issued and NSLI are shown after corrections, and can be
  below zero in a month with only corrections.
- **Why nothing was presented (decision 133).** The after-action report asks
  it, in the salesperson's own words, when the answer is "run, nothing
  presented", and is refused without it. An appointment marked run from the
  Lead page has it given afterwards from the Proposals card, by the
  salesperson or a manager, and it can be changed. It is kept on the
  appointment (`no_demo_reason`, who and when) with an audit event that is
  not in the customer's Activity. It changes nothing about how the
  appointment counts.
- **Not built:** adjustments for an appointment's run or no-show answer that
  is corrected later, or for a proposal marked "added in error" later. Those
  still change the month the appointment was in.

### Close Rate is demo to close (2026-10-13, decision 135)

Built on the branch `checkpoint-close-rate-demo-to-close`. **Not merged; it
waits for Jacob's review.** One change to `SalesFigures` and the line under
the figure; nothing new is stored.

- **Close Rate is the Leads won in the month over the Leads demoed for the
  first time in the month.** A Lead is demoed when an appointment of its that
  was run has a proposal that counts. Before, the wins were divided by the
  Leads first *run*, so an appointment run with nothing presented counted
  against Close Rate as well as against Demo Rate. Now it counts against Demo
  Rate only.
- **Each Lead is one demo, ever, and at most one win.** Three proposals for
  one Lead are one demo; all three accepted, or one of the three, is one win.
  Making more proposals cannot move the rate. A return visit where the
  proposal is presented again is a demo for Demo Rate and not a second demo
  here.
- **A Lead is placed in the month of its first demo,** for the salesperson
  that appointment is with. The win is unchanged from decision 134: the month
  the proposal was accepted, for the salesperson who had the Lead then. So a
  month can read above 100%, and a month with wins and no first demo shows a
  dash and says why.
- **The line under the figure** reads "4 leads won this month, less 1 taken
  back by a correction, against 6 leads first demoed this month."
- **The funnel's first rate, lead to appointment, is not on this page.** The
  plan keeps booking rates with Marketing and Contact Center, by channel (4.1,
  4.6); they are not built.

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

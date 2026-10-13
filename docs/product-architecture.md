BANANA BACKOFFICE — CANONICAL PRODUCT ARCHITECTURE v1

Status: Working canonical architecture
Purpose: This document is the current source of truth for Banana Backoffice product structure, major workflows, domain boundaries, ownership transitions, reporting philosophy, permissions, AI/automation, and implementation direction. The roadmap is an implementation sequence, not the boundary of the product.

STATUS LEGEND
LOCKED — sufficiently understood; use as the default product decision.
CONFIGURABLE — workflow is known, but timing/rates/thresholds belong in settings.
PENDING — architecture is reserved, but exact policy/formula awaits stakeholder confirmation.
DEFERRED — known future capability that should not block current architecture.

1. PRODUCT PURPOSE

Banana Backoffice is the internal operating system for Banana Painting, not merely a CRM. It should eventually support Marketing, Contact Center, Sales, PFAD, Production, subcontractor/crew management, Finance, W-2 field workforce, customer portal, Color Studio, reporting/leadership, AI/automation, and future capabilities not yet specified.

2. CORE PRODUCT PRINCIPLES

LOCKED — Canonical shared records. Customers/Accounts, Contacts, Properties, Leads/Opportunities, Appointments, Projects, Payments, Notes, Conversations, Activity Events, and related records are shared across Centers.

LOCKED — Centers organize work; records do not belong to one Center. Different Centers are role-specific lenses over the same underlying records.

LOCKED — Responsibility is not permission. Assignment controls accountability/default work queues; permissions control whether a user may access a record at all.

LOCKED — Location is an authorization boundary. Contact Center may have deliberately broader customer visibility for central support.

LOCKED — Avoid one giant Project status. Sales, Production, Payment, Job Cost, Color Readiness, Customer Approval, and Financial Close are separate state dimensions.

LOCKED — Fewer pages, stronger workspaces. If multiple pages are simply filtered views of the same work, consolidate them into one workspace with tabs, filters, trays, and contextual actions.

LOCKED — Decision-grade metrics over vanity metrics.

LOCKED — AI is permission-bound. Low-risk repetitive work can be automated; money, policy exceptions, legal/compliance, and personnel consequences require human authority.

LOCKED — Deterministic rules first, AI interpretation second.

3. TOP-LEVEL CENTER ARCHITECTURE

LOCKED parent Centers:
- Marketing
- Sales
- Production
- Finance
- Company / Executive

Customers is not a parent Center. It is a shared page inside operational Centers that need it.

External/customer/worker surfaces:
- My Banana customer portal
- Color Studio
- PFAD worker portal
- Subcontractor portal
- Future Banana Phone mobile app

4. MARKETING CENTER

LOCKED navigation:
- Performance
- Contact Center
- Inbox
- Campaigns
- Customers
- Reports

4.1 Performance
Primary metrics:
- Spend
- Leads
- CPL
- Cost per Appointment
- Cost per Close
- Sold Revenue
- ROI / ROAS
- source-specific Close Rate
- 7-working-day Sales Slot Utilization

Blended Booking Rate is not a headline KPI because normal booking rates differ by channel.

4.2 Contact Center
This is the calling workspace.

New Lead queue:
- highest-priority, speed-to-lead driven
- new lead popup if setter is not in a dial session
- Call or Snooze
- snoozed leads remain in a persistent tray with timer continuing
- if setter is already dialing, new lead injects as the next call rather than interrupting

Warm follow-ups:
- not a static/manual list
- system maintains a shared pool of due follow-ups
- setters see a count such as “19 due now” and Start Dialing
- multiple setters can pull atomically from the same queue without duplicates
- exact cadence is CONFIGURABLE

Current intended phone cadence:
- Day 1: immediate, ~30 minutes later, ~2 hours later
- Day 2: 3 attempts
- Day 3: 2 attempts
- then roughly 2/day until ~12 attempts
- after that phone activity moves to Aged Leads

Aged Leads:
- lower-intensity/long-term
- manually activated by management
- shared queue
- new leads still inject ahead of aged calls

Cancelled Appointments queue (added 2026-10-11; LOCKED by decisions 98 and 117, built in slice 6):
- a queue of its own with its own button and count, separate from New Lead and warm follow-ups
- holds every Lead that came back from Sales with its last appointment cancelled and is not disqualified
- the setter is told on the card that it is a cancelled booking, who cancelled and why, because the script differs
- same cadence and calling hours as other Leads
- rules: slice-5-appointment-rulebook.md, rule 8

Every call attempt is a real record:
- Lead/Contact
- rep
- direction
- timestamp
- number
- duration
- answered/not answered
- disposition
- lifecycle result
- recording/transcript where available

Short-call gaming:
- track suspiciously short calls by setter
- daily manager report surfaces patterns
- do not auto-punish every short call

Connection Rate:
Connected Leads / Total Leads Received.
A connection requires a real two-way response by phone, SMS, email, Messenger, GLSA, or other supported channel. A voicemail or sent message alone does not count.

4.3 Inbox
Shared omnichannel inbox:
- Facebook Messenger
- SMS
- GLSA
- web chat
- scoped email replies
- future compatible channels

AI-first:
- AI handles immediate response, normal qualification, approved FAQs, and booking
- handoff to human when requested, uncertain, outside approved knowledge, upset customer, or authority limit

Handoff transfers transcript plus structured state; human does not restart from zero.

Temporary claim:
- no Claim button
- first successful human send creates a short handling lease
- other users immediately see who is handling it and cannot send
- drafts can remain visible locally
- every send refreshes lease
- inactivity releases claim
- duration CONFIGURABLE, likely 2–5 minutes

Close Conversation:
- reply does not auto-close
- user explicitly closes
- hidden until customer replies or follow-up becomes due

Messaging follow-up:
- awaiting-customer conversations can resurface
- cadence is channel-aware and CONFIGURABLE
- Meta policy windows must be respected
- SMS/email/phone nurture may continue after Messenger closes

Macros:
- personal macros
- team macros
- later AI suggestions

4.4 Campaigns
Marketing team’s deep performance workspace.

Preserve platform metadata where available:
- platform/account
- Campaign ID/name
- Ad Set ID/name
- Ad ID/name
- Form/message context
- UTM/click identifiers
- originating timestamp

Drilldown:
Meta → Campaign → Ad Set → Ad

Show business outcomes, not only media metrics:
- Leads
- Appointments
- Sales
- Sold Revenue
- Cost per Close
- ROI
- Lifetime Sold Revenue
- actual GP later

4.5 Customers
Canonical customer/contact list with search, filters, saved segments, and broadcast actions.

Saved segments may include:
- Past Customers
- Quoted / Not Sold
- Leads / Not Booked
- Color Studio
- Out-of-Service-Area
- Referral Customers
- custom saved segments

Broadcasts:
V1 — SMS and Email
Future — direct mail provider

Broadcast safeguards:
- marketing consent eligibility
- transactional vs marketing permission
- suppression/STOP/unsubscribe
- pacing/throttling
- send windows
- daily caps
- sender/domain reputation protection
- delivery/failure monitoring
- separate operational/conversational sending identities from heavy marketing traffic where appropriate

4.6 Reports
Deep analysis:
- Marketing Source
- Lead Source
- channel booking rates
- spend/ROI by source/location
- campaigns
- referrals
- product-line mix
- geographic expansion demand
- Contact Center trends
- speed-to-lead distribution
- connection rate
- missed-call recovery
- aged lead performance
- setter performance

5. SALES CENTER

LOCKED navigation:
- Performance
- Workspace
- Calendar
- Commissions
- Leaderboard
- Customers
- Reports

5.1 Ownership
Contact Center owns Lead until an appointment is booked.
Appointment landing on salesperson calendar = Lead Issued.
Salesperson then owns confirmation, appointment, cancellations/reschedules, rehashes, follow-up, and outcome.

Added 2026-10-11 (LOCKED by the slice 5 rulebook and decisions 75 to 120; built in slice 6). The rules are in slice-5-appointment-rulebook.md; in short:
- Lead Issued is counted the moment the appointment is saved, once per Lead while it stays with Sales. Reschedule, cancellation and rebooking with the same salesperson add no count.
- "Booked in error" (manager) undoes the handoff and the count.
- A manager can send an issued Lead back to Contact Center. A Lead that should never have been booked stops counting as issued; any other reason leaves it counted.
- After a cancellation the Lead stays with the salesperson for a CONFIGURABLE period (14 days) and is then released to the Cancelled Appointments queue (4.2). The salesperson is warned first.
- The salesperson a Lead was released from can take it back, with no new count.
- OPEN with Sales Leadership: which salesperson an issued Lead counts for after a move or a re-issue.

5.2 Performance
Primary coaching KPI:
- NSLI — Net Sales per Lead Issued

Secondary/diagnostic:
- Demo Rate
- Close Rate
- Cancellation Rate
- Average Job Size
- Close-on-the-Spot Rate
- Pending Rate
- % Pending with Rehash booked during appointment
- Rehash conversion
- Sold Revenue

Demo Rate:
Of attended appointments, how many actually receive a pricing/options presentation.
Use presentation evidence where possible; draft estimate alone is not enough.

Added 2026-10-11 (LOCKED by decision 124):
- Demo Rate is counted from appointments marked run. One with no proposal created counts against the salesperson until a proposal exists, and for them from the day it is created.
- Close Rate counts wins against appointments, not proposals. Several proposals from one appointment with one sold is one win, with nothing counted against the others. Two accepted from one appointment is one win.

Added 2026-10-13 (slice 7 step 7d; build plan: slice-7-appointment-results-plan.md):
- Built on Sales > Performance, for a chosen month at one Location, for everyone there or one salesperson: NSLI, Leads Issued, appointments run, Demo Rate, Close Rate, Sold Revenue, Cancellation Rate. A salesperson sees their own; a manager sees their Locations'.
- Close Rate is counted for each Lead (decision 125): the Leads won in the month over the Leads whose first appointment was run in the month. A Lead is won in the month its proposal was accepted, for the salesperson who had the Lead then (decision 134).
- NSLI is the sold amounts of the month over the Leads issued in the month, each after that month's corrections. There are no refunds to net out yet.
- A month that has passed is not rewritten (decision 133). A sale corrected later, or a Lead Issued taken back later, stays in its month and comes off the month it was corrected in. Sales and their corrections are kept as lines that are never changed (`sale_entries`).
- Not built: Average Job Size, Close-on-the-Spot Rate, Pending Rate, % Pending with Rehash booked, Rehash conversion; targets; the Leaderboard (5.8); a figure across all Locations at once.
- Attribution is LOCKED by decision 133: Lead Issued stays with the first
  salesperson who received that issued Lead; appointment figures follow the
  salesperson assigned when it ran; Sold Revenue and the win follow the
  salesperson who owned the Lead when the proposal was sold. Corrections create
  auditable metric adjustments rather than silently rewriting history.

5.3 Workspace
iPad-first/tablet-responsive in-home selling flow:
- appointment context
- discovery
- measurements
- pricing engine
- options
- presentation
- agreement
- deposit
- after-action report

Do NOT create separate top-level Estimate and Proposal modules.

Concepts remain distinct internally:
- estimate/pricing math
- offer options
- proposal/presentation
- accepted agreement

Added 2026-10-13 from stakeholder discovery:
- Sales confirmation happens 1–4 days before the appointment using the
  confirmation-call script. If no phone connection, the salesperson sends a
  short selfie video covering readiness and inviting a response.
- Discovery captures WNBET: Wants, Needs, Budget, Expectations and Timeline,
  including why the timing matters and whether expectations are realistic.
  Sales produces several consultative options and may freely adjust scope and
  material quality as the conversation develops; there is no rigid 3/9/12
  option generator.
- A customer controlling the conversation is a coaching signal, not yet an
  approved no-demo exclusion. Decision 133 confirms there is no additional
  exclusion: it remains a no-demo and records the coaching reason. Built
  2026-10-13: the after-action report asks why nothing was presented, in the
  salesperson's own words, and it counts as a no-demo whatever is written.

5.4 Calendar
Salespeople manage their own confirmation, rescheduling, cancellations, rehashes, and schedule.
Google Calendar is the external conflict source; personal scheduling stays there.

Added 2026-10-11:
- Built in slice 6: a list of upcoming appointments, cancelled Leads to rebook with the days left, and Leads released to the Contact Center. Rescheduling and cancelling are done on the Lead. Moving an appointment to another salesperson is a manager's.
- Not built: confirmation, rehash, Google Calendar, a calendar grid, and the booking engine in 12 (today a person picks the salesperson and the time).
- LOCKED (decisions 121 and 123): an appointment has a status of its own: cancelled, rescheduled, no-show, or run. This is separate from any proposal's result. One proposal can have several appointments and one appointment can produce several proposals.

5.5 Pending / Rehash
Pending is a commercial status.
Thinking It Over is an objection, not a status.
Salesperson owns Pending for a CONFIGURABLE period.
After ownership expires, it releases to Contact Center Rehash.
Offer Valid Until and Salesperson Ownership Until are separate concepts.

Current ownership/validity rule = LOCKED by stakeholder discovery; future
changes remain configurable.

Added 2026-10-13 from stakeholder discovery:
- Proposal validity defaults to seven days and a salesperson may change it.
- Sales ownership normally ends with that same expiry. On expiry, the Pending
  opportunity releases to Contact Center Rehash.

5.6 After-action report
Goal: 1–2 minutes from the car.
Fields:
- Won / Pending / Lost
- primary option pitched + amount
- selected/sold amount if Won
- main objection if Pending
- Lost Reason + Primary Objection if Lost
- system check whether rehash booked
- short notes
- optional voice note + transcript, preserve original audio

Appointment remains operationally incomplete until report is submitted.

Added 2026-10-11 (LOCKED by decisions 121, 123 and 124; these change how the fields above are read):
- The report first says what happened to the appointment: no-show, or run. (Cancelled and rescheduled are recorded when they happen, not here.)
- An appointment has no result of its own. It shows as run. Only a proposal is Sold, Pending or Lost, and a proposal cannot be given a result before it exists. "Sold" is the word used for Won.
- Run with no proposal created is a no-demo. Demo Rate (5.2) is counted from this and is to be watched.
- One appointment can have several proposals and one proposal several appointments.
- Build plan: slice-7-appointment-results-plan.md. Built so far (2026-10-12): marking an appointment run or no-show, and proposals (a name and a price, tied to the run appointment or appointments they were presented at). A no-show is treated as a cancellation by the customer (decision 125). Since step 7c (2026-10-13): each proposal is Pending, Sold or Lost, with the sold amount, the Lost Reason and the objection; a Lead shows Sold or Lost; a proposal can be corrected or marked "added in error" (decision 128); and the report itself is one short form opened from the appointment. The "system check whether rehash booked" is shown on the Lead, where the return visit is booked. Not built: the voice note (decision 125), and creating a Project when a sale is Sold (rulebook rule 13).
- Close Rate is counted for each Lead, not each appointment (decision 125): a return visit that closes the sale does not lower it, and two proposals sold against one Lead are one win.

Added 2026-10-12 (LOCKED by decision 126; this is what "operationally incomplete" stops):
- Close out first. Once an appointment is over and has no answer, its Lead cannot be booked again, sent back, moved, returned to central or disqualified until someone says run or no-show.
- A salesperson answers their appointments in order: an earlier unanswered one comes before a later one.
- A salesperson with an unanswered appointment is prompted on every page.
- 15 minutes after the appointment ends, its salesperson is reminded once, in the app and by email. No customer details.
- The salesperson is told when a manager answers, corrects or undoes their appointment.
- The habit wanted: closed out straight afterwards, from the car.

5.7 Sales commission
Commission is based on GP dollars.
Effective-dated plan.

Three current plan families:
- $45,000 base + 2% of gross-margin dollars
- $35,000 base + 6% of gross-margin dollars
- no base + 15% of gross-margin dollars
Current rates/base values are confirmed below; plans must be effective-dated.

Added 2026-10-13 from stakeholder discovery:
- Current plans are $45,000 base plus 2% of gross-margin dollars; $35,000 base
  plus 6%; and straight commission at 15%.
- Only straight commission receives the advance. It is 50% of projected
  commission, projected at 50% gross margin (3.75% of revenue), after customer
  acceptance, a full 50% deposit and a completed Sales-to-Production handoff.
- Final commission belongs to the pay period in which the Project is complete,
  paid in full and job costed. Exact meaning of the noted “disqualifier” on the
  $45,000 plan was not defined. Decision 133 gives it no product behavior unless
  Jacob later explicitly defines one.

Advance:
- only if full required 50% deposit actually collected
- 49% = no advance
- advance = 50% of projected commission
- final commission reconciles from actual final GP
- cancellations can create clawbacks

Commission ledger:
- projected
- advance
- final
- GP adjustment
- clawback
- manual adjustment
- scheduled
- paid

5.8 Leaderboard
Company-wide Residential Sold Revenue.
Commercial excluded.

Periods:
- Month-to-Date
- Quarter-to-Date
- Year-to-Date

Recognition:
- prior month/quarter/year champion badges
- defending champion visible
- winner history

5.9 Reports
Sales leadership drilldown:
NSLI, Demo Rate, Cancellation Rate, Close Rate, AJS, Close-on-the-Spot, Pending/Rehash, objections, Lost Reasons, appointment drilldown, revenue by rep, leaderboard history.

Commercial sales = PENDING Sales Leadership; do not force into residential workflow.6. PRODUCTION CENTER

LOCKED navigation:
- Performance
- Workspace
- Schedule
- Subcontractors
- Warranty
- Customers
- Reports

6.1 Performance
Core:
- MTD completed revenue
- current/projected GP %
- production utilization
- projects on time vs late
- AR outstanding
- job-cost closeout timeliness/accuracy
- warranty/callback volume
- active projects at risk

Future:
- projected PM commission / Eligible / At Risk / Ineligible
Current formula is confirmed below; future plans must be effective-dated.

Added 2026-10-13 from stakeholder discovery:
- Employee-facing wording is **gross margin**, **gross-margin dollars** and
  **Production Bonus**, not gross profit or commission for Production.
- Current PM plan: $45,000 base; monthly bonus is
  `(actual gross-margin dollars - $37,500) × 10%`, earned only at a monthly
  gross margin of at least 55%.
- It is paid at the end of the following month only if that following month
  also finishes at or above 55%. Later costs become future-period adjustments.
- The Project completes for this purpose when work is closed and final payment
  is collected. Warranty cost belongs to the current production month rather
  than reopening the original Project; incomplete punch/callback work remains
  part of the original Project before close.

6.2 Workspace
Main PM operating surface:
- pre-production handoffs
- customer onboarding
- Color Readiness
- rough scheduling
- work orders
- PM assignment
- crew/sub assignment
- active projects
- change orders
- customer updates
- punch list
- final walkthrough
- PM-owned AR follow-up
- Brand Ambassador obligations
- job costing
- closeout
- Needs Attention

Do not create separate sidebar pages for Projects, Pre-Production, Work Orders, Change Orders, Punch Lists, Closeout, or PM-side Job Costing.

6.3 Pre-Production
Sold normal project:
- confirmation email/text
- Project Coordinator owns if present; otherwise PM
- manages Sales→Production handoff, colors, customer onboarding, readiness
- rough timeframe before exact schedule
- exact dates typically solidified ~3–6 weeks before start

Added 2026-10-13 (slice 8 step 8a; rulebook rule 13; decision 130; build plan: slice-8-projects-plan.md):
- Built: a Project is made the moment a proposal is Sold, one for each sold proposal, with what was sold, for how much, for whom and where. It has its own page, and shows on the Lead and the customer. Its states are separate fields; so far only Production: New from Sales.
- A corrected sale cancels its Project and keeps it; sold again, the same Project comes back. Jacob accepted this "for now".
- Not built: the handoff questions and Production's Workspace (step 8b), the confirmation email or text, the Coordinator, colors, readiness, the schedule.
- Decision 132 settles the step 8b handoff: structured scope, exclusions,
  promises, timing, access, colors, condition/prep and WNBET priorities are
  required before Production can take the Project; an optional final note is
  allowed. A proposal selects exactly one customer property and its Project
  uses that property.
- Built in slice 8 step 8b (2026-10-13): the property choice on a proposal; the handoff questions, the gate, the salesperson's prompt and next-morning reminder; Production > Workspace with three lists (waiting for handoff, new from Sales, pre-production); a Production Manager taking a job or a manager assigning one, each telling the new owner and the salesperson (20).
- Still not built from 6.2 and 6.3: the confirmation email or text, the Coordinator, the deposit-paid handoff call, customer onboarding, Color Readiness, rough scheduling, work orders and everything after.

6.4 Color Specification
Every normal sold job ultimately has an authoritative Color Specification.

Possible origins:
- Color Studio
- salesperson
- coordinator
- PM
- direct known colors

May be versioned.

Fields:
- room/area
- surface
- manufacturer
- color name/code
- sheen
- product
- notes
- customer approval

Color Plan is exploratory.
Color Specification is production-authoritative.

6.5 Production Schedule
Dates are firm reservations once confirmed, but PMs may manually reschedule.

States:
- rough timeframe
- proposed windows
- crew-selected window
- customer-confirmed schedule
- rescheduled with reason/history

Production utilization target currently ~80%, CONFIGURABLE, to preserve weather/overrun flexibility.
Decision 133 defines it as scheduled productive crew-days divided by available
crew-days for the next six weeks, by Location. Weekends, holidays, approved
time off and unavailable crews are excluded; show the raw percentage and the
planned 20% buffer.

PMs plan same-day starts manually. Established, capable subcontractors may run
their own initial walkthrough; new/unproven crews require staggered starts so
the PM can attend. Active-job contact is daily or twice daily depending on the
subcontractor's experience, communication and rating.

6.6 Work Packages
A Project can contain one or more Work Packages.

Each Work Package can have:
- scope
- payout
- minimum crew size
- photos/video
- expected duration
- proposed date windows
- release strategy
- award status
- accepted subcontractor
- signed agreement
- payout revisions
- completion state

Large/multi-crew projects may split into multiple packages.

6.7 Change Orders vs Sub Work Order Revisions
Customer Change Order:
- changes what customer owes Banana
- amendment to accepted agreement
- signed by customer
- preserves original agreement
- tracks price/schedule delta

Subcontractor Work Order Revision:
- changes what Banana owes sub and/or sub scope
- may happen with or without customer change
- re-signed when material scope/comp changes

Show margin impact.

6.8 Closeout
Customer-facing completion:
- work complete
- walkthrough
- punch list if needed
- punch resolved
- customer signoff
- final balance collected

Internal/financial close:
- BA obligations handled/scheduled/waived
- sub scorecard
- job costing complete
- receipts/support
- Finance reconciliation
- final GP
- payout/commission eligibility
- project financially closed

PM cannot financially close before job costing is complete.

Added 2026-10-13: closeout also requires an authoritative, durable color record
by area/surface: manufacturer/paint, color and sheen. It must remain easy to
retrieve years later. The final walkthrough uses the PCA checklist, internal
inspection first, then customer walkthrough and 100%-satisfied signature; the
PM follows up and gives final-payment instructions. A qualified subcontractor
may conduct it at the PM's discretion after at least five completed Projects
and a 4.5+ rating; responsibility remains with the PM.

6.9 Job-cost governance
PM prepares.
Finance validates.

PM submission = provisional GP.
Finance reconciliation = final approved GP.

Finance handles:
- unassigned COGS
- receipt/cost review
- corrections
- final approval

PM compensation uses final approved GP.

Employee-facing screens use gross margin / gross-margin dollars. Finance may
retain accounting terms where required.

Job-cost accuracy is a PM performance signal. Repeated material corrections should surface to GM/Ops.

Decision 133: a PM accuracy strike is a missing PO or a post-submission
correction above the larger of $250 or 2% of Project revenue. Normal timing and
Finance reclassification are excluded. Notify GM/Ops on the third strike in a
rolling 90-day period; preserve every correction even below the threshold.

6.10 Brand Ambassador closeout
Separate from Project closure.
Track obligations individually:
- review
- testimonial
- video
- yard sign
- project content
- tier-specific items

States:
- Completed
- Partial
- Deferred / extended
- Waived / not pursuing with reason

Project may close financially while BA obligations remain open.
PM owns ensuring completion; another staff member may physically complete.
Review requests should not blindly auto-send after a bad project.
Current tiers/obligations are confirmed below; future changes remain
configurable.

Added 2026-10-13 from stakeholder discovery, the current tiers:
- Bronze, 10%: 30-day yard sign, permission for project content, paper survey.
- Silver, 15%: Bronze plus Facebook and Google reviews with before/after photos,
  and permission to serve as a named/phone reference.
- Gold, 25%: Silver plus video testimonial and a personal social post.
Sales may customize only when the package itself is the sole barrier to closing.

Operationally, the PM must complete, schedule or explicitly waive these items
before Project close, normally the same day or within days.

7. SUBCONTRACTOR MANAGEMENT

Dedicated Production page because Production owns recruit, interview, onboard, train, assign, manage, score, VIP/bench, and offboard.

7.1 Recruiting
Prospect → Screened → Interview Scheduled → Interviewed → Approved/Hired → Onboarding Sent → Active Roster.

7.2 Onboarding / Compliance
Portal gathers:
- business/contact info
- crew size
- capabilities/services
- home base
- tax/legal docs
- insurance
- licenses where required
- master subcontract agreement
- location/state requirements

Base compliance is LOCKED below; Location/state additions are CONFIGURABLE.

Added 2026-10-13 from stakeholder discovery, the base checklist: W-9, general
liability, workers' compensation with waiver of subrogation, signed
subcontractor agreement, and an annually renewed New Mexico gross-receipts-tax
waiver certificate. A subcontractor without required insurance may use Banana's
coverage and receive a documented deduction. Decision 133 starts that deduction
at 10% of subcontractor payout, effective-dated and overridable by Location or
state. Show its dollars on the work order and payable; do not keep a second
competing revenue-based formula.

7.3 Roster
Statuses:
- Active
- VIP
- Benched
- Offboarded
- Pending Compliance / similar

Benched:
- visible/history retained
- no new work
- manual bench/unbench

7.4 Marketplace
PM publishes Work Package.

Release strategy configurable per package/project:
- VIP exclusive X hours
- multi-crew-capable first
- all eligible roster
- direct to one subcontractor

Accept + electronic signature = immediate award.
No PM approval afterward.
First valid signed acceptance wins atomically.
Others see project already awarded.

7.5 Pre-award privacy
Show:
- general area
- generalized map/radius
- route time from sub home base
- scope
- work order
- photos/video
- schedule windows
- payout

Do not expose exact customer address/full identity before award unless required.

7.6 Declines / Price intelligence
Decline reasons:
- price
- timing
- distance
- scope fit
- capacity
- equipment
- other

If price:
“What payout would you take this for?”

PM can revise payout and rebroadcast.
Payout revisions are versioned.
Margin/estimating variance is flagged.

7.7 Multi-crew
Multiple packages on one project.
Each can have minimum crew size.
Large sub may accept multiple packages if capacity allows.
Release can prioritize large/VIP crews first.

7.8 Scheduling
PM offers one or more customer-approved windows.
Sub signs/accepts and selects one.
PM reconfirms with customer.
If customer later changes:
- negotiate move with same sub, or
- release/reassign package

Customer/PM-requested reassignment should not penalize sub as a withdrawal.

7.9 Overrun / failure
Signed duration tracked.

If sub blows timeline:
PM may:
- extend
- keep sub
- remove sub
- create completion package for remaining scope
- reassign/rebroadcast
- set original payout full/partial/none
- record reason

Distinguish contractor-caused overrun from weather/customer/legitimate causes.

Current partial-payout rule: original subcontractor payout minus the amount
required to pay the replacement subcontractor to complete the work.

7.10 Performance scorecard
PM completes scorecard at closeout.
Draft:
- quality
- schedule adherence
- communication
- cleanup/professionalism
- scope adherence
- callback/punch burden
- customer review obtained yes/no
- overall rating
- notes

Visibility to the subcontractor is confirmed below.

Added 2026-10-13: add yard signs and progress photos to the scorecard. The
subcontractor sees the full scorecard and a dedicated “Feedback for the
subcontractor” field, but never the separate internal-notes field.

7.11 VIP
Measurable + management override.

Potential inputs:
- Banana utilization
- PM quality score
- reliability/back-outs
- schedule performance
- callbacks/recovery
- review capture
- compliance

Avoid raw acceptance rate because simultaneous offers make it unfair.

System may show:
- VIP Eligible
- VIP At Risk
- Not Eligible

Actual status remains management-controlled with reason.
Current thresholds/evaluation period are confirmed below.

Added 2026-10-13: current VIP eligibility is under 10% decline, under 10%
price negotiation, at least five completed Projects, 4.5+ rating, no failed
Project in the past 12 months, and under 5% warranty-call rate over 12 months.
Eligibility never grants VIP automatically; the PM decides. Review-bonus and
$100,000 monthly-production bonus rules belong in the subcontractor-compensation
plan, not the VIP status calculation.

8. PAINTER FOR A DAY (PFAD)

PFAD is a separate product line, not a small normal Project.

Separate in:
- reporting
- AJS
- fulfillment
- worker experience
- compensation
- scheduling

Normal-project AJS excludes PFAD.

PFAD metrics:
- average booking value
- average days per booking
- revenue
- GP
- utilization
- revenue per painter-day
- PFAD→standard conversion
- worker performance

8.1 Entry
Small/eligible project identified by Contact Center, AI, self-booker, or Color Studio/Get My Price.
Normal estimator path changes to PFAD sales path.

8.2 Selling
Guided Contact Center flow:
- script/explanation
- pricing
- FAQ
- Send Info
- explainer email/SMS/video
- self-service booking link

Setter attribution preserved if customer converts later.

8.3 Scheduling
Simple whole-day availability.
No complex specialty matching.

Selection uses:
- availability
- location
- quality/performance ranking
- manual override

Potential worker score:
- review capture
- customer/PM quality
- upsell conversion
- referrals
- callbacks
- reliability

8.4 Slot hold / deposit
Customer picks day.
Slot is temporarily held.
Confirmed only when:
- deposit succeeds, or
- authorized person waives deposit

Hold duration CONFIGURABLE.

Payment paths:
- assisted secure payment
- SMS link
- email link

8.5 Worker Portal
Mobile-first web/PWA initially.

Worker can:
- set availability
- see jobs
- see customer/scope/photos
- see earnings
- complete field closeout
- upload before/after photos
- flag Customer Unhappy
- initiate full-project sales handoff and book a rep

8.6 Upsell
“Customer wants more work” → canonical Sales booking engine.
Customer/address/context prefilled.
Originating PFAD worker gets attribution and future incentive if policy supports it.

8.7 Customer Recovery
If worker flags Customer Unhappy before normal completion:
- automatic final charge pauses
- recovery case created
- manager can request full/partial refund
- schedule another PFAD day
- same/different worker
- paid or company-paid recovery day
- send review request after successful recovery

Post-completion satisfaction:
- very satisfied → review/referral path
- below threshold → recovery callback

Current PFAD recovery owner: Contact Center works with the painter and may offer
up to two additional work hours or a partial refund; escalation goes to the
Contact Center Manager.

8.8 Completion / Payment
Happy path:
worker completes → final charge → receipt/invoice → worker earnings released according to policy.

Failed payment:
- AR
- automated retries
- collections
- eventual write-off/external collection as needed

Current subcontractor pay timing is confirmed below. W-2 P4P remains an
intentional deferred formula under decision 133.

Added 2026-10-13: a PFAD day is 8 a.m.–5 p.m.; a subcontractor is currently
budgeted/paid $300 per day on the normal next-Friday subcontractor schedule.
A W-2 painter uses the same approximate budget through Performance Pay. The
exact W-2 formula is deliberately not inferred: its eventual plan is
effective-dated and preserves every pay-period input and result.

8.9 PFAD Rules
Sells time, not guaranteed project completion.
Extra scope:
- use remaining time
- buy another PFAD day
- or handoff to normal Sales

Materials:
- normally homeowner-provided
- optional pickup/add-on may exist
- shopping/travel counts against purchased day
Current pricing/equipment limits, pickup treatment and deposit approval are
confirmed below.

Added 2026-10-13: no ladder above 14 feet, no limewash, cabinets, specialty
staining or spraying; brushes only; standard prep materials included, excessive
prep extra, no primer. Paint is customer-provided or billed at retail; pickup
and shopping consume the day. Up to two after-hours hours may be offered at
$150/hour with approval before 4 p.m. Deposit defaults to 75% and may be
negotiated to 50%; decision 133 sets the approval authority below.

Decision 133: version one has no separate paint-pickup fee. A Location Manager
or Leadership may approve reducing the deposit to 50%; going below 50% requires
Owner approval, a reason and an audit event.

9. FINANCE CENTER

LOCKED navigation:
- Performance
- Cash Management
- Job Costing
- Payroll
- People & Access
- Customers
- Reports

9.1 Performance
Core:
- MTD revenue
- cash collected
- AR/aging
- GP %
- net/operating profit
- unassigned COGS
- projects awaiting financial close
- sub payables
- commission liability/upcoming commission
- refund/credit approvals
- close progress
- P&L-by-the-7th goal

9.2 Cash Management
Possible tabs:
- Receivables
- Payments
- Payouts / provider settlements
- Payables where useful

Receivables:
- balances
- aging
- PM-owned vs Collections-owned
- failed payment
- promises
- disputes
- collection status

Payments:
- card
- ACH
- check
- cash
- financing
- refunds/credits
- exceptions

Payouts are provider-neutral; do not hard-code Stripe.

9.3 Job Costing
Finance reconciliation:
- PM-submitted costs
- awaiting reconciliation
- unassigned COGS
- missing receipts
- corrections
- GP variance
- PM accuracy
- approval/finalization
- late costs/prior-period handling

9.4 Payroll
Supports:
- Sales commission ledger
- PM compensation periods
- PFAD earnings
- W-2 P4P
- clawbacks
- pay periods
- projected/finalized
- payroll export/sync

PM formula is confirmed in 6.1. W-2 painter P4P is intentionally DEFERRED until
Banana supplies the real formula; its data model remains effective-dated.
Warranty work uses a separately approved budget and the worker's normal
effective-dated P4P plan, not an invented reduced rate.

9.5 People & Access
Finance/Admin function:
- users
- roles
- location access
- invites
- activation/deactivation
- employee/account linkage
- payroll linkage later

Subcontractor people-management stays in Production.

9.6 Customers
Same canonical Customers page with Finance-sensitive view:
- invoices
- payments
- balances
- refunds/credits
- financing
- collections history

9.7 Reports
Default = P&L.

Additional:
- AR Aging
- Cash Collections
- GP / Job Cost Variance
- Payables
- Payroll / Commission Liability
- Location comparison
- Monthly Close

9.8 QuickBooks
LOCKED V1 boundary (decision 133):
Backoffice = operational workflows/management visibility.
QuickBooks = formal accounting ledger/system of record.
Approved data syncs to QuickBooks; accounting/payment statuses sync back.
Backoffice never becomes a second independent ledger. Chart of accounts,
journal entries, bank/card reconciliation, tax filing and formal period close
originate in QuickBooks. Backoffice may originate approved operational invoices,
bills, credits and compensation entries and receives posted identity/status.
The QuickBooks subscription is chosen only after an integration spike verifies
invoice, payment, class/location, attachment and API needs.

10. PROJECT ECONOMICS / GP

10.1 COGS
Known direct COGS:
- subcontractor labor
- materials
- workers comp/insurance allocation
- W-2 painter labor
- equipment rentals
- permits
- dump/disposal fees
- warranty/callback direct cost
- other direct fulfillment cost

Sales and PM commissions are not COGS.

Added 2026-10-13: direct COGS also includes frontline payroll tax/PTO/training
and recruiting, frontline vehicles/fuel/maintenance, shop/storage rent, company
GL/workers' compensation/auto insurance allocated to fulfillment, equipment and
tools, value-building fulfillment services, warranty and damage costs. Office
rent and Sales/PM vehicles are not COGS. Employee-facing wording is gross margin.

10.2 Brand Ambassador Floor
Projects priced pre-BA so maximum BA discount still supports target economics.
Production budget uses floor price, never the higher actual sale price.

Example:
Retail $10,000
Max BA 25%
Floor $7,500
55% GP target means max COGS $3,375.

If sold at $10,000, budget does not expand; upside becomes higher GP.

10.3 PM Budget View
Show:
- retail/pre-BA
- floor
- actual contract
- target GP
- target COGS
- committed costs
- actual costs
- remaining budget
- projected final GP
- final GP

Committed costs count before payment/posts so projected GP is truthful.

10.4 Deposit
Normal default 50%.
Location policy configurable:
- percent/flat
- default
- legal limits
- reduced/zero deposit
- approval rules

Sales commission advance only when full 50% actually collected.

10.5 Refunds / Credits
PM may request.
Finance/GM approval required.
Differentiate refund, credit against balance, and bad-debt write-off.

10.6 AR
PM owns initial collection.
Project stays financially open.
Sub/final commissions blocked until collection.
Escalation timing is confirmed below and remains CONFIGURABLE.

Added 2026-10-13: customer payment is due in seven days. PM contacts daily for
the first three days; notices precede and apply the day-7 late fee, repeat for
the day-14 escalation, then continue weekly. At 30 days it transfers to internal
Collections. PM receives a deposit-paid notice and must call a new customer
within 24 hours to complete handoff.

10.7 Bad Debt
Provisional recommendation:
- separate from production COGS/GP
- still attributable to originating project for lifetime economics
Current bad-debt treatment is confirmed below; transaction implementation still
follows the Finance boundary in 9.8.

Added 2026-10-13: bad debt stays below production gross margin and remains
linked to the originating Project. A write-off requires GM and Accounting after
Collections gives up. Project gross margin is not final before payment is
collected and the job is closed.

Finance answers already settled for later planning:
- normal work uses separate deposit and final invoices; the final invoice
  carries tax on the full revenue balance;
- approved “Add work” creates a customer-signed change order and adds it to the
  Project invoice;
- card and financing surcharges are passed to the customer;
- QuickBooks calculates tax from the service address, placed in both bill-to
  and ship-to; estimates/invoices label it “Approximate State & Local Tax
  (subject to change)”;
- late warranty/direct costs after close belong to the current month;
- Backoffice calculates finalized variable compensation for payroll, which may
  independently verify it; clawbacks remain linked to each Project.

Decision 133 adds the Finance operating rules:
- Backoffice records operational payment expectations and immutable external
  references; QuickBooks owns posting, settlement and reconciliation. Cash/check
  entries require receipt evidence and Finance approval; retries are idempotent.
- Keep gross subcontractor payout, insurance deduction and Banana insurance
  expense/allocation as distinct entries.
- Record every late-cost correction; Finance review and visibly flag one above
  the larger of $250 or 2% of Project revenue.
- One operational completion month begins when work is accepted, final payment
  is collected and job costing is complete. Actual posting dates remain evidence,
  not a second performance month. Later warranty costs use their current month.
- Close accruals include signed subcontractor payouts, received-but-unposted
  materials, earned W-2 labor, rentals, permits, known warranty work and other
  approved direct costs; Finance approves and reverses them.
- Refunds reverse cash, credits reduce A/R, cancellation fees are revenue,
  unrecoverable direct costs remain current-month COGS, and bad debt stays below
  gross margin while linked to its Project.
- Backoffice owns operational AP capture/coding/approval and Project assignment;
  QuickBooks owns vendor accounting, payment execution, reconciliation, tax and
  formal journals. Gusto remains the subcontractor direct-deposit executor.
- Backoffice calculates an auditable variable-compensation ledger; an authorized
  person finalizes and sends it to payroll, which may verify without recalculating.
- Starting QuickBooks structure, subject to accountant/legal-entity confirmation:
  one company file where permitted, one chart of accounts, Location for operating
  Location, Class for department/service line, and Corporate for shared costs.
- Monthly close: operations cutoff; PM job-cost submission; AP/payroll accruals;
  cash/A/R reconciliation; unassigned-cost cleanup; Finance review; GM exception
  approval; compensation finalization; P&L review by the seventh. Each step has
  an owner, due date and evidence. Preliminary becomes final after reconciliation,
  accruals, job costs and approvals; later discoveries are linked current-period
  adjustments.
- Controls: immutable external IDs, idempotent sync, daily exceptions, control
  totals by transaction type/Location, monthly A/R/AP/cash/compensation
  reconciliation, and no silent overwrite of posted accounting records.
- First additional reports: sync exceptions, unassigned transactions,
  compensation changes/clawbacks, deposit liability, tax liability, warranty
  cost and close-checklist status.
- Gross Receipts Tax is a liability, excluded from revenue, COGS and gross
  margin; retain QuickBooks' jurisdiction and rate.

11. CUSTOMER / CONTACT WORKSPACE

Full-page workspace, not a small drawer.
Open from any Center/global context.
Back button returns to prior context.

GHL-inspired layout:
Left — contact/account/property/current context
Center — conversations
Right — actions and related records

Sections:
- Conversations
- Notes
- Activity
- Leads
- Appointments
- Projects
- Payments
- Color Plans
- Referrals
- Documents
- contextual records

11.1 Conversations
Unified history:
- SMS
- Messenger
- GLSA
- scoped email
- calls
- voicemail/transcript

Composer defaults to latest usable inbound channel.

11.2 Notes
Internal notes are predictably found here.
Context-linked but unified for readability.
Preserve history; avoid silent rewrite.

11.3 Activity Timeline
Major business events only, not every message:
- Lead created
- source/channel
- booking
- cancel/reschedule
- demo
- Won/Pending/Lost
- proposal accepted
- deposit
- ownership transfer
- production handoff
- start
- change order
- walkthrough
- balance collected
- financial close
- warranty
- refunds/credits

11.4 Contact Center Visibility
Company-wide customer search because central support requires it.
Can see:
- contact info
- communications
- appointments
- assigned staff
- sales outcome
- sold amount/revenue attribution
- customer-service context

Cannot see:
- job-cost detail
- sub payout
- PM scorecard
- sensitive GP/commission data

Past-customer dispute should be transferred or callback-tasked to responsible PM/staff.12. CANONICAL BOOKING ENGINE

One engine powers:
- Contact Center
- AI
- self-booker
- salesperson rescheduling
- rehash
- PFAD where applicable

Inputs:
- Location
- Service
- Project Size
- calendar availability
- salesperson eligibility
- travel feasibility
- selected time
- management preferences
- performance ranking

Customer sees times, not rep rankings.

Intelligent assignment considers:
- eligibility
- calendar
- real road travel
- service/project-size performance
- close rate
- sold amount/revenue
- workload/fairness
- management preferences
- round-robin tie break

Customer convenience wins.

Travel feasibility:
- previous event → candidate
- candidate → next event
- appointment duration/buffer
- home/office anchors
- latest return time

Route provider = DEFERRED research.

Setter may preview full availability before all qualification is complete, but cannot actually select/book until required fields are complete.

If Project Size = Small/PFAD:
Book Appointment becomes Book Painter for a Day.

Sales Slot Utilization:
Filled eligible slots / total eligible slots over next 7 working days.
Booked-but-unconfirmed counts as filled.
Rehash counts.
Cancellation immediately reopens.

13. MY BANANA CUSTOMER PORTAL

One persistent customer account from prospect through repeat customer.

Passwordless-first:
- magic link / one-time code
- multiple authorized Contacts per Account

Portal should always answer:
- What is happening?
- What do you need from me?
- What happens next?

Before purchase:
- Color Plans
- upcoming estimate
- estimator bio/photo
- messages
- proposal
- reschedule/cancel
- PFAD where relevant

After purchase:
- My Projects
- project status/next step
- Color Specification
- schedule
- PM
- documents
- payments
- updates/photos
- change orders
- final walkthrough/signoff
- referrals
- warranty/history

Customer actions:
- reschedule eligible appointments
- approve Color Specs
- sign proposals/change orders/final walkthroughs
- pay
- upload photos
- use Color Studio
- request warranty
- start new project
- refer friend
- Message Banana

All actions use the same backend rules as staff workflows.

Message Banana:
one continuous customer-facing thread.
Internal routing by lifecycle:
- pre-appointment → Contact Center
- Sales phase → salesperson
- sold/pre-production → coordinator if assigned, else PM
- active Production → PM
- completed/no active owner → central/customer care

Fallback/spillover prevents messages being trapped.

14. COLOR STUDIO / COLOR PLAN

Color Plan is design/inspiration, not an operational Project.

Use cases:
- public lead magnet
- customer design tool
- later Sales/Production context

Entry:
- Interior
- Exterior
- Cabinets

Interior flow:
- add rooms
- room type
- photo(s)
- surfaces
- room-specific questions
- lifestyle questionnaire
- visual designer quiz

Quiz:
~7–12 fun design questions, preferably visual.
Should feel like a style quiz, not contractor intake.

AI outputs three directions:
- Recommended
- Timeless
- Bold

Each may include:
- palette
- exact colors
- surface assignment
- sheen
- product
- AI room rendering

Feedback:
- Love this
- Not quite my style

Refinement:
- too dark
- too light
- too warm
- too cool
- too bold
- too neutral
- other

Limit revisions rather than endless generation.

Additional rooms:
- Apply my existing style
- Create a new style

Paint brands:
Default production ecosystem = Sherwin-Williams.
Inspiration may come from SW, Benjamin Moore, Behr, Other.
Preserve original reference and Banana production match/reference.
Do not promise cross-brand exactness based on digital values alone.

Final PDF:
High-value enough to DIY, take to paint store, or hand to another contractor.

Include:
- photos
- renderings
- selected colors
- exact names/codes
- product
- sheen
- surface mapping
- practical notes
- overall summary

Do not estimate paint quantity without actual field measurements.

Lead capture:
User completes most experience anonymously.
At end:
“Where should we send your Color Plan?”
Collect name, phone, email.
Then unlock full plan, email PDF, save My Banana context.

Color Plan contact is not automatically a hot Contact Center lead.

Get My Price:
- strong CTA immediately after plan
- throughout PDF with link/QR
- later SMS/email nurture
- possible offer

Address requested only here.

If in service:
canonical self-booking.

If outside service:
explain not yet serviceable, preserve plan, keep geographic demand signal, retain for marketing if eligible.

Out-of-service Get My Price attempts are expansion intelligence.

15. REFERRALS

Referral remains a canonical Marketing Source.

Backoffice may preserve:
- referring Account
- referral link/code
- offer/campaign
- referred customer
- resulting sold revenue
- reward status

Portal emphasizes usefulness/status + simple Project Credit, not affiliate earnings.

Customer sees:
- easy referral link/code
- Copy button
- credit balance
- Start My Project

Do not expose detailed referred-person status.

Reward earned only once referred project completes and balance is fully paid.

Referral offer is snapshotted at referral creation.
Different campaigns may have different Give/Get values.
Exact values CONFIGURABLE.

16. WARRANTY

Warranty Case linked to original Project.

Can originate from:
- My Banana
- Contact Workspace
- complaint converted to warranty

Customer supplies:
- description
- photos/video

Ownership:
- original PM if active/eligible
- otherwise Location GM
- GM may keep or assign to another PM

Warranty Case:
- customer
- original Project
- description/media
- internal notes
- owner
- scheduled date
- warranty budget
- actual cost
- completion

Execution is lightweight/case-by-case:
- original sub
- different sub
- W-2 painter
- solo warranty tech
- GM/PM
- other

If a person is assigned:
- consume real capacity
- appear on PM production timeline

W-2:
- clock in/out against Warranty Case
- hours are COGS
- warranty work is P4P-eligible, with a separately approved budget
- use the worker's normal effective-dated P4P plan; do not invent a special rate

Subcontractor:
- payout case-by-case
- may be $0 for corrective return
- paid if another sub is used

Warranty costs roll to original Project Lifetime GP.

17. PERFORMANCE BOARDS / LEADERSHIP SCORECARDS

17.1 Performance Boards
Automated, real-time, operational.
Used as working dashboards and TV displays.

17.2 TV Mode
Major boards may support Enter TV Mode:
- large typography
- fewer controls
- stronger visual status
- auto-refresh
- no side nav
- distance-readable
- no dense tables
- optional rotating curated views

Executive TV board may show:
- company MTD sold revenue vs plan
- company GP %
- company net/operating profit %
- location KPI strip
- top Needs Attention
- monthly Sales Leaderboard
- revenue mix: Total / Standard Projects / PFAD by location

Performance Boards define data; TV Mode defines presentation.

17.3 Leadership Scorecards
Separate from automated dashboards.

Weekly accountability:
- few core KPIs
- named owner
- target
- actual
- human-reviewed Green / Yellow / Red
- short note for Yellow/Red

Backoffice can auto-populate objective numbers, but accountable leader submits status.

Provisional GM weekly five:
1. Sold Revenue vs Plan
2. Gross Profit %
3. Net Profit %
4. NSLI
5. Blended Cost per Close

These are outcomes/health. Operational drivers stay on Performance Boards.

GM job: turn red → yellow → green.
CEO job: equip/support/hold GMs accountable to do that.

18. EXECUTIVE / COMPANY CENTER

LOCKED navigation:
- Executive Board
- Leadership Scorecards
- Needs Attention
- Locations
- Reports
- Settings

Executive Board:
live cross-functional operating view, not formal scorecard.
Shows company/location health, revenue mix, GP, profit, top exceptions, leaderboard, drilldown.

Avoid one composite location health score.
Show KPI-level status instead.

Leadership Scorecards:
weekly human-owned accountability.

Needs Attention:
cross-functional exception view, permission-aware, prioritized by consequence/urgency.

Locations:
- GM assignment
- annual plan
- current forecast
- service area
- goals/targets
- sales slot rules
- operating hours
- deposit policy
- legal/compliance settings
- communication defaults
- local tax/payment settings where appropriate

Reports:
cross-company/location analysis.

Settings:
- managed lists
- integrations
- services/project sizes/reasons
- communication providers
- AI/automation thresholds
- payment defaults
- security/audit
- company defaults/location overrides
- tracking-number provisioning/configuration
- compliance defaults

19. AI / EXCEPTION ENGINE

Model:
Exception Engine first, AI interpretation second.

Authority classes:
Autonomous:
- low-risk/reversible/repetitive
- classify attribution
- summarize/transcribe
- route
- standard reminders
- approved FAQs
- approved booking flows

Human-approved:
- unusual customer recovery
- out-of-area exception
- suggested payout adjustment
- judgment-heavy rescheduling

Human-only:
- refunds
- write-offs
- discounts
- deposit waivers
- compensation changes
- termination/benching
- legal/compliance override
- financial approvals

Permissions must be enforced by backend actions, not prompts alone.

Proactive:
Surface meaningful exceptions such as:
- Pending with no rehash
- low Demo Rate
- projects below GP target
- schedule overruns
- job-cost deadline missed
- AR overdue
- expiring sub compliance
- short-call pattern
- low slot utilization

Severity:
- FYI
- Needs Attention
- Urgent

Most normal accountability pressure stays inside Backoffice, not email/SMS.

Tasks vs insights:
If system knows owner + next action → create task automatically.
If it only knows pattern/problem → surface insight.

Briefings:
short role-specific morning/weekly summaries.

Examples:
Salesperson:
- Pending needing follow-up
- missing rehash
- confirmations
- Demo Rate

PM:
- customer updates due
- late project
- incomplete job costing
- AR
- BA obligation

Manager:
- trends
- repeated behaviors
- coaching needs
- KPI misses
- major risks

Feedback:
- Useful
- Not useful
- Dismiss
Optional quick reason.

AI context:
May synthesize all records user is authorized to see.
Must not bypass permissions.
Recommendations should trace to real records.

Natural-language operating layer:
future/target capability for search, explanation, task creation, and permitted actions.

20. CUSTOMER OWNERSHIP TRANSITIONS

Canonical:
Marketing/Contact Center
→ appointment booked

Salesperson
→ issued lead/calendar
→ confirmation
→ appointment
→ Pending/Rehash/Won/Lost

If Won:
Sales
→ Production handoff
→ Coordinator if present
→ PM

If Pending ownership expires:
Sales
→ Contact Center Rehash

Completed:
PM active ownership ends
→ customer pool/retention
→ marketing segments
→ warranty if needed

Every ownership transfer creates Activity event + notification to new owner.

21. MARKETING ATTRIBUTION

Simple attribution model.

Marketing Source:
self-reported influence/awareness.

Lead Source:
where lead actually originated operationally.

Lead Channel:
how customer contacted Banana.

Example:
Marketing Source = Facebook
Lead Source = Google Business Profile
Lead Channel = Phone

Self-booking:
- open-text “How did you hear about us?”
- AI normalizes to canonical source
- high confidence auto-saves
- low confidence human review
- raw response preserved

Staff-assisted:
setter asks live and selects canonical source.

Sources/channels are centrally managed.

Lifetime value:
- primary rollup at Account/customer level
- preserve lead-level attribution underneath
- original acquisition source retained
- repeat inquiry source retained separately

Default marketing reporting:
current-period operating performance.

Secondary:
lead-origin/cohort views when needed.

Marketing revenue basis:
Sold Revenue, not collected revenue.
Change orders and later repeat purchases update lifetime sold value.

22. PAYMENTS / PROVIDERS

Methods:
- cash
- check
- ACH
- card
- financing
- online link

Provider abstraction:
do not hard-code processor fields everywhere.

Possible V1:
- normal Projects via QuickBooks Payments/invoice sync
- PFAD via Stripe-style transaction flow
- all sync to QuickBooks

Backoffice distinguishes:
- amount due
- actual received
- outstanding
- failed attempts
- refunds
- financing funded
- write-offs

Invoice created ≠ cash received.

Invoicing/accounting follows the LOCKED V1 boundary in 9.8 and the approved
Finance rules in 10.7. Provider-specific mapping waits for the integration
spike; it is not guessed.

23. PEOPLE / WORKFORCE

W-2 Field Portal reserved in architecture:
- clock in/out
- Project/Work Package/Warranty selection
- budgeted hours
- actual hours
- labor cost
- P4P
- upcoming pay
- time categories

P4P formula = deliberately DEFERRED until Banana supplies the real formula.
The architecture is effective-dated and evidence-preserving (decision 133).

Subcontractor portal and PFAD portal remain distinct.

24. OPEN STAKEHOLDER DEPENDENCIES

The 2026-10-13 stakeholder questionnaire and decisions 131–133 answer this
former list. All questions and exact approved answers are maintained in
[stakeholder-discovery-open-questions.md](stakeholder-discovery-open-questions.md).
There are no unanswered discovery questions. Intentional deferrals—W-2 P4P,
QuickBooks product-tier selection, legal-entity confirmation and Commercial
Sales discovery—tell future builders not to guess.

Commercial Sales remains deliberately deferred and is not forced into the
residential workflow. Its discovery section is therefore not a blocker.

25. CURRENT IMPLEMENTATION IMPLICATIONS

Current state:
- B1–B6 merged
- B7 built on continuation branch but not finally owner-approved/merged

Do not assume old roadmap remains correct.

Next engineering process:
1. Use this document as target architecture.
2. Read-only gap analysis against B1–B7.
3. Classify current features:
   - keep
   - rename/reorganize
   - extend
   - migrate
   - obsolete/conflicting
4. Re-review B7 against newer Contact Center/nurture model.
5. Finalize/merge B7 only if still a sound foundation.
6. Rewrite roadmap around target architecture.
7. Build first stakeholder-independent foundation slice.
8. Insert stakeholder-confirmed formulas/policies as answers arrive.

Critical B7 review:
“Nurture” should not eventually mean merely a disposition/manual status. Every new Lead participates in multichannel nurture. Existing B7 lifecycle may remain useful internally, but terminology/behavior must be reconciled before extension.

26. IMPLEMENTATION PRIORITY PRINCIPLE

Do not build the entire future product at once.

Prefer foundations that unlock multiple Centers:
- Activity Timeline
- communication/conversation model
- call-attempt records
- customer workspace
- task/exception engine
- role-aware Center navigation
- settings/effective-dated policy framework
- provider abstractions
- Work Package model
- compensation ledger
- reporting/performance-board framework

Do not hard-code unresolved formulas.
When workflow is known but value is unknown, build configurable policy boundaries rather than guessed numbers.

27. DEFINITION OF SUCCESS

Banana Backoffice succeeds when:
- each role knows exactly where to work
- ownership is explicit
- admin/follow-up work is hard to forget
- managers see exceptions early
- financial truth is difficult to game
- customers experience one coherent Banana relationship
- Sales and Production are accountable to controllable economics
- leadership sees company/location health without drowning in noise
- surface UX stays simple while the operating model underneath is sophisticated
- future capabilities can be added without breaking core domain boundaries

END OF ARCHITECTURE v1




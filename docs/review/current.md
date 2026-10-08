# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-08 · **Branch:** `office/9-slice-4-plan-scheduled-work-and-the-due`
· **Pull request:** <https://github.com/jakerans/bananabackoffice/pull/3>
**State:** Job #9 (Plan) is written, reviewed and approved by the Reviewer, and
pushed. It is not merged yet. It changes documents only. No application code,
test, setting or database change is in it. Nothing in slice 4 is built.

## Step

Slice 4 plan: scheduled work and the due queue. A planning job, not a build step.

## Objective

Record Jacob's fifteen slice 4 decisions (made in conversation on 2026-10-08) in
the Backoffice documents, and write the implementation plan for slice 4 in three
build steps: 4a settings and timezones, 4b the engine, 4c the queue screen.

## What changed

Seven files, all under `docs/`. Nothing else is in the change.

| Document | What changed |
| --- | --- |
| [../decisions.md](../decisions.md) | New decisions 65 to 73. 65 to 72 are Jacob's decisions as he gave them. 73 records the design and the choices made while planning. No existing entry was rewritten. |
| [../slice-4-plan.md](../slice-4-plan.md) | New. The plan: the scheduler, timezone, retry and failure contracts, the atomic claim, the data model, permissions, navigation, audit events, the tests for steps 4a, 4b and 4c, and what the staging server needs. |
| [../roadmap.md](../roadmap.md) | Slice 4 is now "planned and approved for implementation; not built", with its three steps and a new "Approved slice 4 boundary" section. The contracts that were pending are marked decided. |
| [../permission-model.md](../permission-model.md) | A new "Slice 4" section: two new permissions, `settings.contact_center.manage` and `leads.work_queue`, with their matrix rows and rules. Marked approved, not built. |
| [../navigation-map.md](../navigation-map.md) | A new "Slice 4 navigation changes" section: the Contact Center settings page and the Call Queue. Marked approved, not built. |
| [../README.md](../README.md) | One row added to the document index for the plan. |
| This file | Replaced with this handoff. |

### What is recorded as approved

- **Implementation approval.** Jacob's approval of this batch is his explicit
  approval to build slice 4 as described in decisions 65 to 73 and the plan, and
  nothing beyond it (decision 65).
- **Two earlier decisions are changed, each by a new entry:**
  - Decision 67 changes one slice 3 rule (decision 63): logging a contact attempt
    now advances the cadence. It still never changes lifecycle status or assignee.
  - Decision 68 supersedes the single company timezone in decision 40: each
    Location has its own, default US Eastern.
- **Two follow-ups are recorded and not authorized:** moving existing screens to
  each Location's time (decision 68), and a confirmation text for Leads that
  arrive outside calling hours once texting exists (decision 72).

### The three build steps

1. **4a — Settings and timezones.** A timezone on each Location. A Contact Center
   page under Company > Settings for the cadence, calling hours and days, and the
   hold length. Nothing uses the settings yet.
2. **4b — The engine.** Every new Lead enters the cadence. Logging an attempt
   schedules the next one. Taking, holding, skipping and putting back all work,
   with no screen. The background timer runs every minute.
3. **4c — The queue screen.** The Call Queue in Marketing > Contact Center: the
   due-now count, Next lead, the card, the manager summary and the "Cadence
   finished, no contact" list.

Each step is built, checked on SQLite and MySQL 8.4, reviewed and reported on its
own before the next begins. Details are in [../slice-4-plan.md](../slice-4-plan.md).

## Tests and checks, with their real results

| Check | Result |
| --- | --- |
| Complete local check (`composer test`), run by the office | **Passed** |
| GitHub: Checks and tests (SQLite) | **Passed** — [run](https://github.com/jakerans/bananabackoffice/actions/runs/37855813862/job/113579588057) |
| GitHub: Migrations and tests (MySQL 8.4) | **Passed** — [run](https://github.com/jakerans/bananabackoffice/actions/runs/37855813862/job/113579588438) |
| Independent review by the Reviewer | **Approved**, after 2 fix rounds of at most 3 |
| Click-through testing in a browser | **Not run.** The office has no test browser or test environment for this project yet. |

The job changed documents only, so the passing checks show that nothing existing
was broken. They do not test the plan's content; that was the Reviewer's reading.
The Architect wrote the plan, so the Architect's own approval is not an
independent check.

The Reviewer confirmed that only the seven intended documentation files changed,
that the plan satisfies the work order, and that the revised attempt-recording
sequence keeps the append-only contract and the transactional retry guarantees.

### What the two fix rounds changed

Both rounds changed only [../slice-4-plan.md](../slice-4-plan.md) and this file.

- **Round 1.** Skip cooldowns are now kept per Lead and per setter, so one
  setter's skip cannot erase another's. The claim rechecks queue permission,
  timezone, calling hours and skip cooldown before any Lead is returned,
  including one the setter already holds. Deactivating a Location ends holds in
  its own transaction. Each has tests listed in step 4b.
- **Round 2.** A counted attempt is now inserted once with its cadence cycle and
  step already set, and the cadence advances only after that insert succeeds. A
  failure rolls back both, and a repeated submission never advances the cadence
  twice.

## Architectural decisions made while planning

The design is recorded in decision 73 and the plan. Three points from the fix
rounds are worth knowing:

- **Deactivating a Location normally has no holds to end.** The existing rule
  refuses to deactivate a Location that still has active Leads. The new hook is a
  safeguard for a Lead that reaches the Location mid-deactivation, and for state
  a crash left behind. The refusal rule is unchanged.
- **A hold taken just before calling hours are narrowed is not ended by the
  save.** The setter's next press of Next lead ends it, with the reason "calling
  hours have closed". Decision 71 lists disqualifying, routing and losing access
  as the things that end a hold at once; hours were not added to that list.
- **A skip cooldown is a fixed moment,** the skip time plus the hold length at
  the time. It is not cleared early when an attempt is counted.

## Deviations from the work order

None. No conflict between documents was found that the new decisions do not
settle, so no question stopped the job.

## Known limitations

- **Nothing is built.** This job is a plan.
- **Every new Lead is due straight away,** including one recorded while staff are
  already talking to the customer, and one a Sales Consultant records for
  themselves. Logging that first conversation as a connected attempt takes it out
  of the queue.
- **A Lead that leaves the queue does not return by itself.** Resuming a Deferred
  Follow-up or reopening a disqualified Lead leaves it out until someone uses Put
  back in queue.
- **Between 4b and 4c** new Leads on staging will be in the cadence with no queue
  screen to work them from. Staging holds fictitious data only.
- **Leads that exist before 4b is deployed never enter the queue.**
- **The staging server's scheduler has not been checked.** See below.

## Decisions needed from Jacob

### 1. Confirm or change the eleven choices made while planning

Jacob's decisions left these details open. Each was filled with the plainest
reading and written into decision 73 and the plan. None adds a feature.
**Recommendation: keep all eleven as written.** Any can be changed before or
during the build. Choices 1, 2, 6 and 7 are the ones with a real alternative.

1. **Day 1, "about 2 hours later".** Read as about two hours after the Lead
   arrived: calls at once, 30 minutes later, then 90 minutes after that. The
   other reading is two hours after the second call. Either can be set on the
   settings page, so this only fixes the starting value.
2. **"Spread evenly through calling hours".** The first call of the day is at
   opening and the rest at equal gaps. With the default hours: 8:00 am, 12:20 pm
   and 4:40 pm for a three-call day; 8:00 am and 2:30 pm for a two-call day.
   The alternative is to centre them (about 10:10 am, 2:30 pm, 6:50 pm), which
   avoids calling at the moment of opening but leaves the early morning unused.
3. **What uses up an attempt.** An outbound phone call that did not connect. A
   text or email that was only sent is kept as evidence and does not count. A
   real two-way conversation on any channel takes the Lead out of the queue.
4. **Skip reasons.** A short fixed list: No usable phone number; Needs a manager
   to look at it; I cannot make this call right now; Other (with a note).
5. **"Not offered straight back to the same setter".** Not for one hold length,
   5 minutes by default. Everyone else is offered it at once.
6. **Which Leads a setter is given.** Every due Lead at any Location they have
   queue access at, whichever Location is selected at the top of the screen.
   This differs from the Leads list, which follows the selected Location. It was
   chosen because setters do not pick, and a Lead waiting at a Location nobody
   has selected would otherwise go uncalled. The alternative, following the
   selected Location, is more consistent with the Leads list but risks that.
7. **Who counts as a manager.** Decision 71 gives Put back in queue to "people
   who may already assign Leads there". Call Center Agents may already assign
   Leads, so they get Put back in queue and see the manager summary too. In
   practice everyone who works the queue sees the summary. Making this narrower
   would need a new permission, which is outside what was approved.
8. **Put back in queue** is offered for any Lead that has left the queue and is
   New, Working or Qualified. A disqualified Lead must be reopened first, and a
   Deferred Follow-up resumed first, under the existing rules. A "Cadence
   finished" Lead starts again at attempt 1; any other carries on where it
   stopped.
9. **A Location's timezone** is set on the existing Locations page by whoever may
   manage Locations (the Owner today), from a list of United States zones.
10. **Limits on the settings page.** Hours move in 15-minute steps and must be at
    least an hour long; at least one calling day; a hold of 1 to 30 minutes; a
    cadence of up to 14 days with up to 6 calls a day.
11. **Contact Center still opens on Leads.** Call Queue is added beside it.

### 2. Possibly one step in Forge, during step 4b

The background timer needs Laravel's scheduler running every minute on the
staging server. Backoffice has never had a scheduled task, so it may not be on.
This has not been checked.

- **What is needed:** one scheduler entry for the staging site in Forge (server
  "BackOffice"), run as user `forge` every minute, with the command
  `php artisan schedule:run`. If the site has a "Laravel scheduler" switch,
  turning it on is the whole step. No queue worker, no new package, no paid
  service.
- **Who does it:** the office does not change Forge. Step 4b will check whether
  it is already running and, if it is not, hand Jacob the exact step with the
  real site path.
- **If it is not on:** the queue still works. The manager summary shows a warning
  that the timer has not run, and the tidy-up pass does not happen.

Details: "The server's scheduler on staging" in the plan. Nothing is needed from
Jacob for this now.

### 3. Still open from an earlier job

Three critical npm warnings remain, all from the `vite-plus` build tool, and wait
for Jacob's decision on raising it from 0.3.0 to 0.3.3. The record is in
[../security-notes.md](../security-notes.md), "npm dependency audit (2026-10-08)".
Slice 4 does not touch it.

## Browser review

Not applicable: this job changes documents only and there is nothing new to see
on any screen. Step 4c requires a browser review, and the plan says so.

## Merge, staging and production

- **Merge:** not merged yet. The office merges into `main` only if every GitHub
  check passes on the final commit.
- **Staging:** the project deploys to staging by itself after a merge. The office
  does not deploy and has not verified any deployment. This change holds
  documents only.
- **Production:** not touched. Production always waits for Jacob.

## What happens next

Nothing starts by itself. Step 4a begins only when Jacob says so.

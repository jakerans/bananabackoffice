# Backoffice — Project Documentation

Backoffice is the internal operating system for Banana Painting.

These documents are the durable record of what we are building and why. Read them
before starting work, and update them in the same change that alters what they describe.

| Document | What it answers |
| --- | --- |
| [project-brief.md](project-brief.md) | What Backoffice is, the business rules that never bend, and how we work. |
| [product-architecture.md](product-architecture.md) | The canonical target product structure, domain boundaries and direction. Its status labels describe product certainty, not permission to implement. |
| [roadmap.md](roadmap.md) | Checkpoints, the current checkpoint, and what has been built. |
| [checkpoint-b.md](checkpoint-b.md) | The B1–B7 as-built record and the older unbuilt B8–B12 plan retained as historical context. |
| [slice-4-plan.md](slice-4-plan.md) | The approved plan for slice 4: the call cadence, the due queue and its three build steps. All three steps (4a, 4b and 4c) are merged; the owner's browser review of 4c (the Call Queue screen) is still owed. |
| [slice-5-appointment-rulebook.md](slice-5-appointment-rulebook.md) | The slice 5 rulebook for appointment issuance and ownership handoff (decisions 75 to 84). Design only: nothing in it is built or approved to build. Later appointment work must follow it. |
| [slice-7-appointment-results-plan.md](slice-7-appointment-results-plan.md) | The proposed plan for what follows a booked appointment: run or no-show, proposals, their results, the after-action report and the first Sales figures. Approved in four steps (decision 125). Step 7a is merged (2026-10-12), with what decision 126 added to it. Steps 7b, 7c and 7d are merged (2026-10-12 and 2026-10-13). Slice 7 is complete. It says from the product architecture why it is next. |
| [slice-8-projects-plan.md](slice-8-projects-plan.md) | The proposed plan for what follows a sale: a Project made when a sale is Sold, and the handoff to Production. Approved in two steps on 2026-10-13 (decision 130), with one Project for each sold proposal. Steps 8a and 8b are merged (2026-10-13); slice 8 is complete. Decision 132 settled the handoff questions and property rule for 8b, and decision 134 changes how a Project is named. It says from the product architecture why it is next, and what it keeps and replaces from the earlier plan for Projects (decision 54). |
| [slice-9-pending-rehash-plan.md](slice-9-pending-rehash-plan.md) | The proposed plan for Pending ownership and Rehash: a "valid until" day on every pending proposal, the Lead released to a Rehash call queue when it is up, and the Pending figures. Approved on 2026-10-13 in three steps (decision 137). All three steps are merged (decisions 138, 139 and 141); slice 9 is complete. It says from the product architecture why it is next. |
| [slice-10-pre-production-plan.md](slice-10-pre-production-plan.md) | The plan for pre-production on a sold job: the welcome call and rough timeframe, the Color Specification, and readiness. Approved on 2026-10-13 in three steps (decision 141). All three steps are merged (decisions 142 to 144); the slice is complete. It says from the product architecture why it is next. |
| [slice-16-document-storage-plan.md](slice-16-document-storage-plan.md) | The proposed plan for storing files in the Wasabi buckets of decision 156: the storage itself with backup and restore, compliance documents, and photos of the work. Approved on 2026-10-15 in three steps (decision 157); 16a and 16b are merged (decisions 158 and 160); 16c is built and awaiting review. |
| [slice-15-sales-figures-plan.md](slice-15-sales-figures-plan.md) | Average Job Size and Close-on-the-Spot Rate on Sales > Performance: the plan and what was built, overnight on 2026-10-15 under decision 155; merged (decision 157). |
| [slice-14-work-and-closeout-plan.md](slice-14-work-and-closeout-plan.md) | The plan for the work and its completion: work started and finished, running over, the walkthrough and punch list, the customer's signoff, and the scorecard. Built overnight on 2026-10-15 under decision 155, recommendations taken as answers; merged (decision 157). It says from the product architecture why it is next. |
| [slice-13-giving-work-plan.md](slice-13-giving-work-plan.md) | The plan for giving work to a subcontractor: payout, the award and the work order, inside Backoffice. Approved on 2026-10-14 in three steps (decision 151); All three steps are merged (decisions 152 to 154); the slice is complete. It says from the product architecture why it is next. |
| [slice-12-subcontractors-plan.md](slice-12-subcontractors-plan.md) | The plan for subcontractors: the roster and recruiting, and compliance. Approved on 2026-10-14 in two steps (decision 148); Both steps are merged (decisions 149 and 150); the slice is complete. It says from the product architecture why it is next. |
| [slice-11-schedule-plan.md](slice-11-schedule-plan.md) | The plan for Work Packages and the exact schedule: proposed windows, the customer-confirmed dates, rescheduling with its history, and Production > Schedule. Approved on 2026-10-14 in three steps (decision 144); All three steps are merged (decisions 145 to 147); the slice is complete. It says from the product architecture why it is next. |
| [stakeholder-discovery-open-questions.md](stakeholder-discovery-open-questions.md) | All 31 discovery questions and their approved answers. Decisions 132 and 133 accepted every recommendation; intentional deferrals are marked so future builders do not guess. |
| [slice-6-appointments-plan.md](slice-6-appointments-plan.md) | The plan for appointment booking, built from the slice 5 rulebook in three steps. All three steps (6a, 6b and 6c) are built and merged; slice 6 is complete. |
| [navigation-map.md](navigation-map.md) | Every center and menu item, its stable ID, and whether it is built yet. |
| [permission-model.md](permission-model.md) | Who can do what, and where. Roles, permissions, location rules. |
| [design-system.md](design-system.md) | How the Backoffice design system is applied in code. |
| [decisions.md](decisions.md) | Important architectural decisions and the reasoning behind them. |
| [security-notes.md](security-notes.md) | Security review findings: what was fixed and what is still open. |
| [collaboration.md](collaboration.md) | How work is planned, reviewed and approved: who decides, the step-by-step workflow, and the limits that are never traded for speed. |
| [review/current.md](review/current.md) | The review handoff: what is waiting on the owner right now, and the decisions it needs. Temporary; not part of the record. |

## Status labels used in these documents

Two separate statuses must be stated for future work:

- **Product direction:** `LOCKED`, `CONFIGURABLE`, `PENDING` or `DEFERRED`, as
  defined in [product-architecture.md](product-architecture.md).
- **Implementation:** `proposed`, `approved` or `built`.

`LOCKED` means the target direction is settled enough to plan against. It does
not authorize a roadmap slice, code change, merge or deployment. **Proposed**
implementation remains unapproved; **approved** implementation may be built only
within its stated slice; **built** records what exists now. An undecided product
detail is **PENDING** and must not be guessed.

## Environments

| Environment | Where | Database | Email |
| --- | --- | --- | --- |
| Local | Herd, `https://backoffice.test` (Herd's "Secure" option, on since 2026-10-10) | SQLite | Log file or local mail catcher; nothing real is sent |
| Staging | Forge, `https://bananabackoffice-xg8eqczb.on-forge.com`, deploys from `main` | MySQL 8.4 | Resend, real email |
| Production | Not set up yet | MySQL 8.4 | Resend |

Staging is deployed and operational; see [roadmap.md](roadmap.md), "Staging".

## Running Backoffice locally

| Task | Command |
| --- | --- |
| Install and build everything | `composer setup` |
| Apply database changes | `php artisan migrate` |
| Create the first Owner account | `php artisan backoffice:create-owner` |
| Fill a local database with fictitious staff and locations | `php artisan db:seed` |
| Rebuild styles and fonts after a design change | `npm run build` |
| Run every check (style, static analysis, tests) | `composer test` |

The seeder only does anything in local development. Every seeded account uses the
password `password`; the Owner is `owner@example.com`.

Invitation emails use the mail settings in `.env`. With `MAIL_MAILER=log` they are
written to `storage/logs/laravel.log` instead of being sent. Staging and production
send real email through Resend (`MAIL_MAILER=resend`); the Resend key lives only in
each server's environment settings. See [decisions.md](decisions.md), decision 22.

## Where things live

| What | Where |
| --- | --- |
| Roles and permissions | `app/Enums/Role.php`, `app/Enums/Permission.php` |
| "Can this person do this, here?" | `app/Models/User.php`, `app/Providers/AppServiceProvider.php` |
| Current location | `app/Support/Access/CurrentLocation.php` |
| Navigation definition | `app/Support/Navigation/Navigation.php` |
| Staff operations (invite, grant, deactivate) | `app/Actions/Staff/` |
| Leads: capture, list and detail | `app/Models/Lead.php`, `app/Policies/LeadPolicy.php`, `app/Actions/Leads/`, `resources/views/pages/leads/` |
| Phone number matching | `app/Support/Contact/PhoneNumber.php` |
| Address lookup (Mapbox) and the structured address | `app/Support/Addresses/` |
| Service areas and the suggested location | `app/Support/ServiceAreas/`, `app/Actions/Locations/` |
| The five managed lists and their Settings page | `app/Enums/ManagedList.php`, `app/Models/ManagedListOption.php`, `app/Actions/Lists/`, `resources/views/pages/settings/⚡lists.blade.php` |
| Contact Center settings (versioned rows) and their page | `app/Models/ContactCenterSettings.php`, `app/Actions/ContactCenter/`, `resources/views/pages/settings/⚡contact-center.blade.php` |
| Location timezone and the list of allowed zones | `app/Support/Queue/LocationTimezones.php`, `app/Actions/Locations/ChangeLocationTimezone.php` |
| The Call Queue page, the shared contact-attempt form and the queue's read-only counts | `resources/views/pages/marketing/⚡call-queue.blade.php`, `app/Livewire/Concerns/RecordsContactAttempts.php`, `resources/views/partials/contact-attempt-form.blade.php`, `resources/views/components/queue-time.blade.php`, `app/Support/Queue/DueQueue.php` |
| Call cadence and queue: the cadence row, its history and the timer's heartbeat | `app/Models/LeadCadence.php`, `app/Models/LeadQueueEvent.php`, `app/Models/SchedulerHeartbeat.php` |
| Calling hours, when an attempt is due, and "may this person be given this Lead now?" | `app/Support/Queue/CallingHours.php`, `app/Support/Queue/CadenceSchedule.php`, `app/Support/Queue/QueueEligibility.php` |
| Queue actions (enter, advance, end, claim, skip, end hold, put back, tick) | `app/Actions/Queue/` |
| The every-minute queue timer | `app/Console/Commands/QueueTick.php`, `routes/console.php` |
| Real-concurrency test support (separate PHP processes; tests only) | `tests/Support/QueueProcesses.php`, `tests/Support/queue-process.php` |
| The call queue: engine, screen and settings | `app/Actions/Queue/`, `app/Support/Queue/`, `resources/views/pages/marketing/⚡call-queue.blade.php`, `resources/views/pages/settings/⚡contact-center.blade.php`, `app/Actions/ContactCenter/` |
| Appointments: booking, the Appointment card, Sales > Calendar and the bell | `app/Actions/Appointments/`, `app/Models/Appointment.php`, `app/Models/LeadIssue.php`, `app/Notifications/AppointmentBooked.php`, `resources/views/pages/leads/⚡appointment.blade.php`, `resources/views/pages/sales/⚡calendar.blade.php`, `resources/views/pages/notifications/⚡bell.blade.php` |
| Recording an audit event | `app/Actions/Audit/RecordAuditEvent.php`, `app/Enums/AuditAction.php`, `app/Models/AuditEvent.php` |
| The short names records are stored under | `app/Providers/AppServiceProvider.php` (`configureRecordNames`) |
| Application shell | `resources/views/layouts/app.blade.php`, `resources/views/components/shell/` |
| Pages | `resources/views/pages/`, `resources/views/sections/` |
| Design tokens | `resources/css/app.css` |

## Reference material

`docs/reference/` holds local design reference only (competitor CRM screenshots and
Banana brand files). It may contain real business and customer information from
another company. It is excluded from version control and must never be copied into
`public/`, committed, or deployed. See [decisions.md](decisions.md), decision 9.

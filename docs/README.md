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
| [slice-4-plan.md](slice-4-plan.md) | The approved plan for slice 4: the call cadence, the due queue and its three build steps. Step 4a is merged; 4b (the engine, no screen) is built and awaiting review; 4c is not built. |
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
| Local | Herd, `http://backoffice.test` | SQLite | Log file or local mail catcher; nothing real is sent |
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
| Call cadence and queue: the cadence row, its history and the timer's heartbeat | `app/Models/LeadCadence.php`, `app/Models/LeadQueueEvent.php`, `app/Models/SchedulerHeartbeat.php` |
| Calling hours, when an attempt is due, and "may this person be given this Lead now?" | `app/Support/Queue/CallingHours.php`, `app/Support/Queue/CadenceSchedule.php`, `app/Support/Queue/QueueEligibility.php` |
| Queue actions (enter, advance, end, claim, skip, end hold, put back, tick) | `app/Actions/Queue/` |
| The every-minute queue timer | `app/Console/Commands/QueueTick.php`, `routes/console.php` |
| Real-concurrency test support (separate PHP processes; tests only) | `tests/Support/QueueProcesses.php`, `tests/Support/queue-process.php` |
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

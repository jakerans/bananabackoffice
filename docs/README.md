# Backoffice — Project Documentation

Backoffice is the internal operating system for Banana Painting.

These documents are the durable record of what we are building and why. Read them
before starting work, and update them in the same change that alters what they describe.

| Document | What it answers |
| --- | --- |
| [project-brief.md](project-brief.md) | What Backoffice is, the business rules that never bend, and how we work. |
| [roadmap.md](roadmap.md) | Checkpoints, the current checkpoint, and what has been built. |
| [checkpoint-b.md](checkpoint-b.md) | The current checkpoint in full: leads, customers and projects. The records, the rules, the build steps and the open decisions. |
| [navigation-map.md](navigation-map.md) | Every center and menu item, its stable ID, and whether it is built yet. |
| [permission-model.md](permission-model.md) | Who can do what, and where. Roles, permissions, location rules. |
| [design-system.md](design-system.md) | How the Backoffice design system is applied in code. |
| [decisions.md](decisions.md) | Important architectural decisions and the reasoning behind them. |
| [security-notes.md](security-notes.md) | Security review findings: what was fixed and what is still open. |

## Status labels used in these documents

- **Approved** — agreed with the owner. Build to this.
- **Proposed** — recommended, awaiting owner approval. Do not build yet.
- **Open** — a decision that has not been made. Do not guess.

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
| The five managed lists and their Settings page | `app/Enums/ManagedList.php`, `app/Models/ManagedListOption.php`, `app/Actions/Lists/`, `resources/views/pages/settings/⚡lists.blade.php` |
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

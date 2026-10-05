# Backoffice — Project Documentation

Backoffice is the internal operating system for Banana Painting.

These documents are the durable record of what we are building and why. Read them
before starting work, and update them in the same change that alters what they describe.

| Document | What it answers |
| --- | --- |
| [project-brief.md](project-brief.md) | What Backoffice is, the business rules that never bend, and how we work. |
| [roadmap.md](roadmap.md) | Checkpoints, the current checkpoint, and what has been built. |
| [navigation-map.md](navigation-map.md) | Every center and menu item, its stable ID, and whether it is built yet. |
| [permission-model.md](permission-model.md) | Who can do what, and where. Roles, permissions, location rules. |
| [design-system.md](design-system.md) | How the Backoffice design system is applied in code. |
| [decisions.md](decisions.md) | Important architectural decisions and the reasoning behind them. |
| [security-notes.md](security-notes.md) | Security review findings: what was fixed and what is still open. |

## Status labels used in these documents

- **Approved** — agreed with the owner. Build to this.
- **Proposed** — recommended, awaiting owner approval. Do not build yet.
- **Open** — a decision that has not been made. Do not guess.

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
written to `storage/logs/laravel.log` instead of being sent.

## Where things live

| What | Where |
| --- | --- |
| Roles and permissions | `app/Enums/Role.php`, `app/Enums/Permission.php` |
| "Can this person do this, here?" | `app/Models/User.php`, `app/Providers/AppServiceProvider.php` |
| Current location | `app/Support/Access/CurrentLocation.php` |
| Navigation definition | `app/Support/Navigation/Navigation.php` |
| Staff operations (invite, grant, deactivate) | `app/Actions/Staff/` |
| Application shell | `resources/views/layouts/app.blade.php`, `resources/views/components/shell/` |
| Pages | `resources/views/pages/`, `resources/views/sections/` |
| Design tokens | `resources/css/app.css` |

## Reference material

`docs/reference/` holds local design reference only (competitor CRM screenshots and
Banana brand files). It may contain real business and customer information from
another company. It is excluded from version control and must never be copied into
`public/`, committed, or deployed. See [decisions.md](decisions.md), decision 9.

# Backoffice — Project Documentation

Backoffice is the internal operating system for Banana Painting.

These documents are the durable record of what we are building and why. Read them
before starting work, and update them in the same change that alters what they describe.

| Document | What it answers |
| --- | --- |
| [project-brief.md](project-brief.md) | What Backoffice is, the business rules that never bend, and how we work. |
| [roadmap.md](roadmap.md) | Checkpoints, the current checkpoint, and the step-by-step plan for it. |
| [navigation-map.md](navigation-map.md) | Every center and menu item, its stable ID, and whether it is built yet. |
| [permission-model.md](permission-model.md) | Who can do what, and where. Roles, permissions, location rules. |
| [design-system.md](design-system.md) | How the Backoffice design system is applied in code. |
| [decisions.md](decisions.md) | Important architectural decisions and the reasoning behind them. |

## Status labels used in these documents

- **Approved** — agreed with the owner. Build to this.
- **Proposed** — recommended, awaiting owner approval. Do not build yet.
- **Open** — a decision that has not been made. Do not guess.

## Reference material

`docs/reference/` holds local design reference only (competitor CRM screenshots and
Banana brand files). It may contain real business and customer information from
another company. It is excluded from version control and must never be copied into
`public/`, committed, or deployed. See [decisions.md](decisions.md), decision 9.
